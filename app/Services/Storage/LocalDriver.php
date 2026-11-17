<?php

namespace App\Services\Storage;

use RuntimeException;

class LocalDriver implements StorageDriver
{
    private string $root;

    public function __construct(array $config)
    {
        $this->root = rtrim($config['path'] ?? '', '/');
    }

    public function test(): array
    {
        if ($this->root === '') {
            return ['ok' => false, 'message' => 'Не задан путь'];
        }
        if (! is_dir($this->root) && ! @mkdir($this->root, 0775, true)) {
            return ['ok' => false, 'message' => "Нельзя создать папку {$this->root}"];
        }
        $probe = $this->root.'/.probe-'.bin2hex(random_bytes(4));
        if (@file_put_contents($probe, 'ok') === false) {
            return ['ok' => false, 'message' => "Нет права записи в {$this->root}"];
        }
        @unlink($probe);
        $free = @disk_free_space($this->root);

        return ['ok' => true, 'message' => 'Запись работает'.($free ? '. Свободно: '.round($free / 1073741824, 1).' ГБ' : '')];
    }

    public function put(string $path, $stream): int
    {
        $full = $this->full($path);
        if (! is_dir(dirname($full)) && ! @mkdir(dirname($full), 0775, true)) {
            throw new RuntimeException('Не удалось создать папку '.dirname($full));
        }
        $out = fopen($full.'.part', 'wb');
        $bytes = stream_copy_to_stream($stream, $out);
        fclose($out);
        if ($bytes === false) {
            throw new RuntimeException('Ошибка записи '.$full);
        }
        rename($full.'.part', $full);

        return $bytes;
    }

    public function list(string $prefix = ''): array
    {
        $base = $this->full($prefix);
        if (! is_dir($base)) {
            return [];
        }
        $out = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            if ($file->isFile() && ! str_ends_with($file->getFilename(), '.part')) {
                $out[] = [
                    'path' => ltrim(substr($file->getPathname(), strlen($this->root)), '/'),
                    'size' => $file->getSize(),
                    'modified' => $file->getMTime(),
                ];
            }
        }

        return $out;
    }

    public function delete(string $path): void
    {
        @unlink($this->full($path));
    }

    public function readStream(string $path)
    {
        return fopen($this->full($path), 'rb');
    }

    public function temporaryUrl(string $path, int $seconds = 900): ?string
    {
        return null;
    }

    private function full(string $path): string
    {
        if (str_contains($path, '..')) {
            throw new RuntimeException('Недопустимый путь');
        }

        return $this->root.'/'.ltrim($path, '/');
    }
}
