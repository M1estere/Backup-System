<?php

namespace App\Services\Sync;

use App\Models\Project;
use App\Models\ProjectMirror;
use App\Models\ProjectSync;
use App\Models\Server;
use App\Services\Backup\RemoteScript;
use App\Services\Ssh\ServerChecker;
use App\Services\Ssh\SshExecutor;
use RuntimeException;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Синхронизация кода: родительский сервер → панель (поток) → каждое зеркало.
 * Данные нигде не сохраняются на диск панели; на серверах ничего не устанавливается.
 */
class SyncRunner
{
    private const MARK_BEGIN = '# BEGIN backup-panel';

    private const MARK_END = '# END backup-panel';

    public function execute(ProjectSync $sync, ?callable $heartbeat = null): void
    {
        $sync->update(['status' => 'running', 'started_at' => now(), 'finished_at' => null, 'error' => null]);
        $project = $sync->project()->with(['parentServer', 'mirrors.server'])->firstOrFail();

        try {
            $mirrors = $project->mirrors->where('enabled', true);
            if ($mirrors->isEmpty()) {
                throw new RuntimeException('У проекта нет включённых зеркал');
            }
            $parent = $this->check($sync, $project->parentServer, 'родительский сервер');

            $ok = 0;
            $fail = 0;
            foreach ($mirrors as $mirror) {
                $heartbeat && $heartbeat();
                try {
                    $this->syncMirror($sync, $project, $parent, $mirror, $heartbeat);
                    $mirror->update(['last_status' => 'success', 'last_synced_at' => now(), 'last_error' => null]);
                    $ok++;
                } catch (Throwable $e) {
                    $fail++;
                    $mirror->update(['last_status' => 'failed', 'last_error' => mb_substr($e->getMessage(), 0, 1000)]);
                    $sync->addLog("Зеркало «{$mirror->server->name}»: ошибка — ".$e->getMessage(), 'error');
                }
            }
            $sync->update([
                'status' => $fail === 0 ? 'success' : ($ok === 0 ? 'failed' : 'partial'),
                'finished_at' => now(),
                'error' => $fail ? "Зеркал с ошибкой: $fail из ".($ok + $fail) : null,
            ]);
        } catch (Throwable $e) {
            $sync->addLog('Ошибка: '.$e->getMessage(), 'error');
            $sync->update(['status' => 'failed', 'finished_at' => now(), 'error' => mb_substr($e->getMessage(), 0, 1000)]);
        }
    }

    private function check(ProjectSync $sync, Server $server, string $role): Server
    {
        $sync->addLog("Проверка: $role {$server->name} ({$server->host})");
        $r = (new ServerChecker)->check($server->fresh());
        if (! $r['ok']) {
            throw new RuntimeException("$role «{$server->name}» недоступен: ".$r['message']);
        }

        return $server->fresh();
    }

    private function syncMirror(ProjectSync $sync, Project $project, Server $parent, ProjectMirror $mirror, ?callable $heartbeat): void
    {
        $target = rtrim($mirror->target_path, '/');
        $source = rtrim($project->source_path, '/');
        if ($target === '' || $source === '') {
            throw new RuntimeException('Пути не должны быть пустыми или корнем «/»');
        }
        if ($mirror->server_id === $project->parent_server_id && $target === $source) {
            throw new RuntimeException('Зеркало совпадает с источником (тот же сервер и путь)');
        }
        $dst = $this->check($sync, $mirror->server, 'зеркало');
        $sync->addLog("Копирование {$parent->host}:$source → {$dst->host}:$target");

        $excludes = array_merge($this->lines($project->excludes), $this->lines($mirror->excludes));
        $ex = implode(' ', array_map(fn ($e) => '--exclude='.RemoteScript::q($e), $excludes));

        $srcCmd = 'cd '.RemoteScript::q($source).' || exit 98; '
            .'( tar -czf - '.$ex.' . ; rc=$?; [ $rc -eq 1 ] && rc=0; exit $rc )';
        $dstCmd = 'mkdir -p '.RemoteScript::q($target).' && tar -xzf - --no-same-owner -C '.RemoteScript::q($target);

        $srcSsh = new SshExecutor($parent);
        $dstSsh = new SshExecutor($dst);
        $script = 'set -o pipefail; SSHPASS="$P_SRC" '.$this->wrap($srcSsh->argv($srcCmd))
            .' | SSHPASS="$P_DST" '.$this->wrap($dstSsh->argv($dstCmd))
            .'; echo "RC=${PIPESTATUS[0]},${PIPESTATUS[1]}" >&2';

        $p = new Process(['bash', '-c', $script], null, ['P_SRC' => $parent->password, 'P_DST' => $dst->password], null, (float) config('backup.max_runtime', 43200));
        $p->start();
        while ($p->isRunning()) {
            $heartbeat && $heartbeat();
            usleep(500000);
        }
        $err = trim($p->getErrorOutput());
        if (! preg_match('/RC=(\d+),(\d+)/', $err, $m) || $m[1] !== '0' || $m[2] !== '0') {
            $tail = mb_substr(preg_replace('/RC=\d+,\d+/', '', $err), -600);
            throw new RuntimeException('копирование не удалось (источник/приёмник: '.($m[1] ?? '?').'/'.($m[2] ?? '?').') '.trim($tail));
        }
        $sync->addLog('Файлы переданы');

        $this->applyProtection($sync, $mirror, $dstSsh, $target);
        $sync->addLog("Зеркало «{$mirror->server->name}»: готово");
    }

    /** Basic auth и запрет индексации: управляемый блок в .htaccess + robots.txt (после каждой синхронизации заново). */
    private function applyProtection(ProjectSync $sync, ProjectMirror $mirror, SshExecutor $ssh, string $target): void
    {
        $q = fn ($s) => RemoteScript::q((string) $s);
        $block = [];
        if ($mirror->noindex) {
            $block[] = '<IfModule mod_headers.c>';
            $block[] = '  Header set X-Robots-Tag "noindex, nofollow, noarchive"';
            $block[] = '</IfModule>';
        }
        if ($mirror->basic_auth) {
            if (! $mirror->basic_auth_user || ! $mirror->basic_auth_password) {
                throw new RuntimeException('Включён Basic auth, но не заданы логин и пароль');
            }
            $block[] = 'AuthType Basic';
            $block[] = 'AuthName "Restricted"';
            $block[] = 'AuthUserFile '.$target.'/.htpasswd-backup-panel';
            $block[] = 'Require valid-user';
        }

        $s = 'cd '.$q($target)." || exit 98\n";
        // убрать прежний управляемый блок (если был)
        $s .= "if [ -f .htaccess ]; then sed '/".self::MARK_BEGIN."/,/".self::MARK_END."/d' .htaccess > .htaccess.bp-old; else : > .htaccess.bp-old; fi\n";
        if ($block) {
            $content = self::MARK_BEGIN."\n".implode("\n", $block)."\n".self::MARK_END."\n";
            $s .= 'printf %s '.$q($content)." > .htaccess.bp-new; cat .htaccess.bp-old >> .htaccess.bp-new; mv .htaccess.bp-new .htaccess\n";
        } else {
            $s .= "if [ -s .htaccess.bp-old ]; then mv .htaccess.bp-old .htaccess; elif [ -f .htaccess ]; then rm -f .htaccess; fi\n";
        }
        $s .= "rm -f .htaccess.bp-old .htaccess.bp-new\n";

        if ($mirror->basic_auth) {
            $s .= 'umask 077; printf "%s:%s\n" '.$q($mirror->basic_auth_user).' "$(printf %s '.$q($mirror->basic_auth_password).' | openssl passwd -apr1 -stdin)" > .htpasswd-backup-panel; chmod 644 .htpasswd-backup-panel'."\n";
        } else {
            $s .= "rm -f .htpasswd-backup-panel\n";
        }
        if ($mirror->noindex) {
            $s .= "printf 'User-agent: *\\nDisallow: /\\n# managed by backup-panel\\n' > robots.txt\n";
        } else {
            $s .= "if [ -f robots.txt ] && grep -q 'managed by backup-panel' robots.txt; then rm -f robots.txt; fi\n";
        }
        if ($mirror->chown) {
            if (! preg_match('/^[a-z0-9_.-]+(:[a-z0-9_.-]+)?$/i', $mirror->chown)) {
                throw new RuntimeException('Некорректное значение владельца (user:group)');
            }
            $s .= 'chown -R '.$mirror->chown.' . 2>/dev/null || exit 96'."\n";
        }
        $s .= "exit 0\n";

        $r = $ssh->run('sh -s', 600, $s);
        if ($r['code'] !== 0) {
            throw new RuntimeException('не удалось применить защиту (код '.$r['code'].') '.trim($r['err']));
        }
        $sync->addLog('Защита: noindex='.($mirror->noindex ? 'да' : 'нет').', basic auth='.($mirror->basic_auth ? 'да' : 'нет').($mirror->chown ? ", владелец {$mirror->chown}" : ''));
    }

    private function wrap(array $argv): string
    {
        return implode(' ', array_map('escapeshellarg', $argv));
    }

    private function lines(?string $text): array
    {
        return array_values(array_filter(array_map('trim', preg_split('/\R/', (string) $text)), 'strlen'));
    }
}
