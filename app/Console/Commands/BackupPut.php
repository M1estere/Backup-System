<?php

namespace App\Console\Commands;

use App\Models\Storage;
use App\Services\Storage\StorageManager;
use Illuminate\Console\Command;

/** Служебная команда: прочитать STDIN и записать в хранилище. Используется StreamTee; печатает JSON-результат. */
class BackupPut extends Command
{
    protected $signature = 'backup:put {storage : ID хранилища} {path : путь внутри хранилища}';

    protected $description = 'Записать поток из STDIN в хранилище (служебная команда)';

    protected $hidden = true;

    public function handle(StorageManager $manager): int
    {
        try {
            $storage = Storage::findOrFail((int) $this->argument('storage'));
            $bytes = $manager->driver($storage)->put($this->argument('path'), STDIN);
            $this->output->writeln(json_encode(['ok' => true, 'bytes' => $bytes]));

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->output->writeln(json_encode(['ok' => false, 'error' => mb_substr($e->getMessage(), 0, 500)], JSON_UNESCAPED_UNICODE));

            return self::FAILURE;
        }
    }
}
