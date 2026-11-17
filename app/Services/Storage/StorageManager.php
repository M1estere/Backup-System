<?php

namespace App\Services\Storage;

use App\Models\Storage;
use InvalidArgumentException;

class StorageManager
{
    public function driver(Storage $storage): StorageDriver
    {
        return match ($storage->type) {
            Storage::TYPE_S3 => new S3Driver($storage->config),
            Storage::TYPE_SFTP => new SftpDriver($storage->config),
            Storage::TYPE_LOCAL => new LocalDriver($storage->config),
            default => throw new InvalidArgumentException("Неизвестный тип хранилища: {$storage->type}"),
        };
    }

    /** Проверить подключение и сохранить результат (для SFTP — запомнить ключ сервера при первом подключении). */
    public function check(Storage $storage): array
    {
        $driver = $this->driver($storage);
        $result = $driver->test();

        if ($driver instanceof SftpDriver && $result['ok'] && $driver->seenHostKey && empty($storage->config['host_key'])) {
            $cfg = $storage->config;
            $cfg['host_key'] = $driver->seenHostKey;
            $storage->config = $cfg;
            $result['message'] .= ' (ключ сервера запомнен)';
        }

        $storage->forceFill([
            'status' => $result['ok'] ? 'ok' : 'error',
            'last_checked_at' => now(),
            'last_error' => $result['ok'] ? null : $result['message'],
        ])->save();

        return $result;
    }
}
