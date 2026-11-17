<?php

namespace App\Services\Storage;

use phpseclib3\Net\SFTP;
use RuntimeException;

class SftpDriver implements StorageDriver
{
    private ?SFTP $sftp = null;

    /** Ключ сервера, увиденный при последнем подключении (для TOFU). */
    public ?string $seenHostKey = null;

    public function __construct(private array $config) {}

    private function connect(): SFTP
    {
        if ($this->sftp) {
            return $this->sftp;
        }
        $sftp = new SFTP($this->config['host'], (int) ($this->config['port'] ?? 22), 15);
        $key = $sftp->getServerPublicHostKey();
        if (! $key) {
            throw new RuntimeException('Не удалось получить ключ сервера (хост недоступен?)');
        }
        $this->seenHostKey = $key;
        if (! empty($this->config['host_key']) && ! hash_equals($this->config['host_key'], $key)) {
            throw new RuntimeException('Ключ SSH-сервера изменился! Возможна подмена. Подключение отклонено.');
        }
        if (! $sftp->login($this->config['username'], $this->config['password'])) {
            throw new RuntimeException('Неверный логин или пароль');
        }

        return $this->sftp = $sftp;
    }

    public function test(): array
    {
        try {
            $sftp = $this->connect();
            $base = $this->base();
            if (! $sftp->is_dir($base) && ! $sftp->mkdir($base, -1, true)) {
                return ['ok' => false, 'message' => "Нельзя создать папку $base"];
            }
            $probe = $base.'/.probe-'.bin2hex(random_bytes(4));
            if (! $sftp->put($probe, 'ok')) {
                return ['ok' => false, 'message' => "Нет права записи в $base"];
            }
            $sftp->delete($probe);

            return ['ok' => true, 'message' => 'Подключено, запись работает'];
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => $e->getMessage()];
        }
    }

    public function put(string $path, $stream): int
    {
        $sftp = $this->connect();
        $full = $this->full($path);
        $dir = dirname($full);
        if (! $sftp->is_dir($dir) && ! $sftp->mkdir($dir, -1, true)) {
            throw new RuntimeException("Не удалось создать папку $dir");
        }
        if (! $sftp->put($full.'.part', $stream)) {
            throw new RuntimeException('Ошибка записи по SFTP: '.$sftp->getLastSFTPError());
        }
        $sftp->rename($full.'.part', $full);

        return (int) $sftp->filesize($full);
    }

    public function list(string $prefix = ''): array
    {
        $sftp = $this->connect();
        $out = [];
        $walk = function (string $dir) use (&$walk, &$out, $sftp) {
            $items = $sftp->rawlist($dir) ?: [];
            foreach ($items as $name => $a) {
                if ($name === '.' || $name === '..') {
                    continue;
                }
                $p = $dir.'/'.$name;
                if (($a['type'] ?? 1) === 2) {
                    $walk($p);
                } elseif (! str_ends_with($name, '.part')) {
                    $out[] = [
                        'path' => ltrim(substr($p, strlen($this->base())), '/'),
                        'size' => (int) $a['size'],
                        'modified' => (int) $a['mtime'],
                    ];
                }
            }
        };
        $walk($this->full($prefix));

        return $out;
    }

    public function delete(string $path): void
    {
        $this->connect()->delete($this->full($path));
    }

    public function readStream(string $path)
    {
        $tmp = fopen('php://temp/maxmemory:'.(64 * 1024 * 1024), 'w+b');
        $this->connect()->get($this->full($path), $tmp);
        rewind($tmp);

        return $tmp;
    }

    public function temporaryUrl(string $path, int $seconds = 900): ?string
    {
        return null;
    }

    private function base(): string
    {
        return rtrim($this->config['base_path'] ?? '/', '/');
    }

    private function full(string $path): string
    {
        if (str_contains($path, '..')) {
            throw new RuntimeException('Недопустимый путь');
        }

        return $this->base().'/'.ltrim($path, '/');
    }
}
