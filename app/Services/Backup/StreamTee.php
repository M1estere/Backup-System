<?php

namespace App\Services\Backup;

/**
 * Параллельная запись одного потока в несколько хранилищ.
 * На каждое хранилище запускается дочерний процесс `artisan backup:put`, читающий STDIN.
 * Между источником и каждым получателем стоит буфер в памяти (config backup.tee_buffer_mb): пока в нём есть место,
 * медленный получатель (например, S3 на загрузке очередной части) не тормозит источник и остальные хранилища.
 * Когда буфер заполнен — источник ждёт (память не растёт). Диск панели не используется.
 */
class StreamTee
{
    /** @var array<int, array{proc: mixed, stdin: resource|null, out: string, err: string, alive: bool, writeError: ?string, queue: \SplQueue, off: int, queued: int, progress: float}> */
    private array $children = [];

    private int $cap;

    public function __construct(private array $storageIds, private string $remotePath)
    {
        $this->cap = max(1, (int) config('backup.tee_buffer_mb', 64)) * 1048576;
        foreach ($storageIds as $id) {
            $out = tempnam(sys_get_temp_dir(), 'bpo');
            $err = tempnam(sys_get_temp_dir(), 'bpe');
            $proc = proc_open(
                [PHP_BINARY, base_path('artisan'), 'backup:put', (string) $id, $remotePath],
                [0 => ['pipe', 'r'], 1 => ['file', $out, 'w'], 2 => ['file', $err, 'w']],
                $pipes,
                base_path()
            );
            if ($proc) {
                stream_set_blocking($pipes[0], false);
                stream_set_write_buffer($pipes[0], 0);
            }
            $this->children[$id] = [
                'proc' => $proc, 'stdin' => $proc ? $pipes[0] : null, 'out' => $out, 'err' => $err,
                'alive' => (bool) $proc, 'writeError' => $proc ? null : 'Не удалось запустить процесс записи',
                'queue' => new \SplQueue, 'off' => 0, 'queued' => 0, 'progress' => microtime(true),
            ];
        }
    }

    public function write(string $chunk): void
    {
        $len = strlen($chunk);
        foreach ($this->children as &$c) {
            if ($c['alive']) {
                $c['queue']->enqueue($chunk); // строка одна на всех (refcount), память не умножается
                $c['queued'] += $len;
            }
        }
        unset($c);

        $this->pump();
        // обратное давление: источник ждёт, только если буфер самого медленного получателя заполнен
        while ($this->aliveCount() > 0 && $this->maxQueued() > $this->cap) {
            $this->drainFullest();
        }
    }

    public function aliveCount(): int
    {
        return count(array_filter($this->children, fn ($c) => $c['alive']));
    }

    private function maxQueued(): int
    {
        $m = 0;
        foreach ($this->children as $c) {
            if ($c['alive']) {
                $m = max($m, $c['queued']);
            }
        }

        return $m;
    }

    /** Записать в pipe-ы сколько они примут без блокировки (ничего не ждёт). */
    private function pump(): void
    {
        foreach ($this->children as &$c) {
            if (! $c['alive']) {
                continue;
            }
            while (! $c['queue']->isEmpty()) {
                $buf = $c['queue']->bottom();
                $n = @fwrite($c['stdin'], $c['off'] > 0 ? substr($buf, $c['off']) : $buf);
                if ($n === false) {
                    $this->kill($c, 'Получатель закрыл соединение раньше времени');
                    break;
                }
                if ($n === 0) {
                    break; // pipe полон
                }
                $c['progress'] = microtime(true);
                $c['off'] += $n;
                $c['queued'] -= $n;
                if ($c['off'] >= strlen($buf)) {
                    $c['queue']->dequeue();
                    $c['off'] = 0;
                } else {
                    break; // частичная запись — pipe полон
                }
            }
        }
        unset($c);
    }

    /**
     * Дождаться, пока самый загруженный получатель освободит место, и целиком записать ему очередной кусок
     * блокирующей записью (одна крупная запись эффективнее множества мелких частичных).
     * Если получатель не принимает данные 10 минут — он отключается.
     */
    private function drainFullest(): void
    {
        $id = null;
        $max = -1;
        foreach ($this->children as $cid => $c) {
            if ($c['alive'] && ! $c['queue']->isEmpty() && $c['queued'] > $max) {
                $max = $c['queued'];
                $id = $cid;
            }
        }
        if ($id === null) {
            return;
        }
        $c = &$this->children[$id];

        $r = $e = null;
        $w = [$c['stdin']];
        if (! @stream_select($r, $w, $e, 600)) {
            $this->kill($c, 'Хранилище не принимает данные более 10 минут (таймаут)');

            return;
        }
        $buf = $c['queue']->bottom();
        $data = $c['off'] > 0 ? substr($buf, $c['off']) : $buf;
        stream_set_blocking($c['stdin'], true);
        $n = @fwrite($c['stdin'], $data);
        if ($c['stdin']) {
            stream_set_blocking($c['stdin'], false);
        }
        if ($n === false || $n < strlen($data)) {
            $this->kill($c, 'Получатель закрыл соединение раньше времени');

            return;
        }
        $c['queue']->dequeue();
        $c['off'] = 0;
        $c['queued'] -= strlen($data);
        $c['progress'] = microtime(true);
    }

    private function kill(array &$c, string $why): void
    {
        $c['alive'] = false;
        $c['writeError'] = $why;
        $c['queue'] = new \SplQueue;
        $c['queued'] = 0;
        if ($c['stdin']) {
            @fclose($c['stdin']);
            $c['stdin'] = null;
        }
    }

    /**
     * Дослать буфер, закрыть потоки, дождаться завершения.
     *
     * @return array<int, array{ok: bool, bytes: int, error: ?string}>
     */
    public function close(): array
    {
        // досылаем остатки буферов (блокируясь, пока получатели читают)
        while (true) {
            $this->pump();
            $pending = false;
            foreach ($this->children as $c) {
                if ($c['alive'] && ! $c['queue']->isEmpty()) {
                    $pending = true;
                }
            }
            if (! $pending) {
                break;
            }
            $this->drainFullest();
        }

        $result = [];
        foreach ($this->children as $id => $c) {
            if ($c['stdin']) {
                @fclose($c['stdin']);
            }
            $code = is_resource($c['proc']) ? proc_close($c['proc']) : -1;
            $json = json_decode((string) @file_get_contents($c['out']), true);
            $err = trim((string) @file_get_contents($c['err']));
            @unlink($c['out']);
            @unlink($c['err']);

            if (is_array($json) && ($json['ok'] ?? false) && $code === 0 && $c['alive']) {
                $result[$id] = ['ok' => true, 'bytes' => (int) $json['bytes'], 'error' => null];
            } else {
                $msg = $json['error'] ?? $c['writeError'] ?? ($err !== '' ? mb_substr($err, 0, 500) : "процесс записи завершился с кодом $code");
                $result[$id] = ['ok' => false, 'bytes' => 0, 'error' => $msg];
            }
        }

        return $result;
    }
}
