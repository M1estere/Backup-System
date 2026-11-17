<?php

namespace App\Services\Ssh;

use App\Models\Server;

class ServerChecker
{
    /** Проверить доступность сервера, наличие нужных утилит и место. Сохраняет результат в модель. */
    public function check(Server $server): array
    {
        $note = '';
        if (! $server->host_key) {
            $key = SshExecutor::scanHostKey($server);
            if (! $key) {
                return $this->fail($server, 'Сервер недоступен (не удалось получить ключ SSH)');
            }
            $server->host_key = $key;
            $note = ' Ключ сервера запомнен.';
        }

        $ssh = new SshExecutor($server);
        $script = 'echo "whoami=$(id -un)"; echo "uid=$(id -u)"; '
            .'for c in tar gzip mysqldump pg_dump nice ionice; do command -v $c >/dev/null 2>&1 && echo "has_$c=1" || echo "has_$c=0"; done; '
            .'echo "tmp_free_kb=$(df -Pk /tmp | awk \'NR==2{print $4}\')"';
        $r = $ssh->run($script, 30);

        if ($r['code'] !== 0) {
            $err = trim($r['err'] ?: $r['out']);
            if (str_contains($err, 'Host key verification failed') || str_contains($err, 'REMOTE HOST IDENTIFICATION')) {
                $err = 'Ключ SSH-сервера изменился! Возможна подмена. Подключение отклонено.';
            } elseif (str_contains($err, 'Permission denied')) {
                $err = 'Неверный логин или пароль';
            }

            return $this->fail($server, $err ?: 'Ошибка SSH (код '.$r['code'].')');
        }

        $caps = [];
        foreach (explode("\n", trim($r['out'])) as $line) {
            if (str_contains($line, '=')) {
                [$k, $v] = explode('=', $line, 2);
                $caps[trim($k)] = trim($v);
            }
        }

        $missing = array_filter(['tar', 'gzip'], fn ($c) => ($caps["has_$c"] ?? '0') !== '1');
        $msg = 'Подключено как '.($caps['whoami'] ?? '?').'.';
        if ($missing) {
            $msg .= ' Нет утилит: '.implode(', ', $missing).'.';
        }
        if (($caps['has_mysqldump'] ?? '0') !== '1') {
            $msg .= ' mysqldump не найден (дампы MySQL недоступны).';
        }
        if (($caps['uid'] ?? '0') !== '0') {
            $msg .= ' Не root: часть файлов может быть недоступна.';
        }

        $server->forceFill([
            'capabilities' => $caps,
            'status' => $missing ? 'error' : 'ok',
            'last_checked_at' => now(),
            'last_error' => $missing ? $msg : null,
        ])->save();

        return ['ok' => ! $missing, 'message' => $msg.$note];
    }

    private function fail(Server $server, string $message): array
    {
        $server->forceFill([
            'status' => 'error',
            'last_checked_at' => now(),
            'last_error' => $message,
        ])->save();

        return ['ok' => false, 'message' => $message];
    }
}
