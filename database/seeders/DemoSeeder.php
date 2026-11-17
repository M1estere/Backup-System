<?php

namespace Database\Seeders;

use App\Models\BackupJob;
use App\Models\Server;
use App\Models\Storage;
use Illuminate\Database\Seeder;

/** Демо-данные для ЛОКАЛЬНОГО тестового окружения (docker compose --profile test). Не запускать в проде. */
class DemoSeeder extends Seeder
{
    public function run(): void
    {
        $server = Server::firstOrCreate(['name' => 'Тестовый сервер'], [
            'host' => 'test-source', 'port' => 22, 'username' => 'root', 'password' => 'rootpass',
        ]);

        $s3 = Storage::firstOrCreate(['name' => 'S3 (тест, S3Mock)'], [
            'type' => 's3',
            'config' => ['bucket' => 'backups', 'region' => 'us-east-1', 'key' => 'x', 'secret' => 'x',
                'endpoint' => 'http://s3mock:9090', 'path_style' => true, 'prefix' => 'panel'],
        ]);
        $win = Storage::firstOrCreate(['name' => 'Windows (тест, SFTP)'], [
            'type' => 'sftp',
            'config' => ['host' => 'test-win', 'port' => 22, 'username' => 'winbackup', 'password' => 'backuppass', 'base_path' => '/data/backups'],
        ]);

        // Рабочий стол Windows (папка DESKTOP_DIR из .env, внутри контейнера /mnt/desktop)
        Storage::firstOrCreate(['name' => 'Рабочий стол Windows (тест)'], [
            'type' => 'local', 'config' => ['path' => '/mnt/desktop'],
        ]);

        $job = BackupJob::firstOrCreate(['name' => 'Тестовый сайт'], [
            'server_id' => $server->id,
            'paths' => ['/var/www/site'],
            'excludes' => ['cache'],
            'db_config' => ['enabled' => true, 'host' => 'localhost', 'port' => 3306, 'username' => 'site', 'password' => 'sitepass', 'databases' => ['testsite']],
            'mode' => 'stream',
            'run_at' => '03:00:00',
        ]);

        if ($job->storageLinks()->count() === 0) {
            $job->storageLinks()->create(['storage_id' => $s3->id, 'keep_daily' => 7, 'keep_weekly' => 4, 'keep_monthly' => 6]);
            $job->storageLinks()->create(['storage_id' => $win->id, 'keep_daily' => 7, 'keep_weekly' => 0, 'keep_monthly' => 0]);
        }
    }
}
