<?php

namespace App\Services\Backup;

use App\Jobs\RunBackupJob;
use App\Models\BackupJob;
use App\Models\Run;
use Illuminate\Support\Facades\Cache;

class BackupLauncher
{
    /**
     * Создать запуск и поставить в очередь.
     *
     * @return array{run: ?Run, error: ?string}
     */
    public function launch(BackupJob $job, string $trigger = 'manual', string $tier = 'manual'): array
    {
        // защита от двойного клика: проверка и создание запуска — под короткой блокировкой
        return Cache::lock('launch-job-'.$job->id, 10)->block(5, fn () => $this->doLaunch($job, $trigger, $tier));
    }

    private function doLaunch(BackupJob $job, string $trigger, string $tier): array
    {
        if ($job->storageLinks()->count() === 0) {
            return ['run' => null, 'error' => 'У задания не выбрано ни одного хранилища'];
        }

        // Не запускать второй экземпляр. Записи старше 12 часов считаем «зависшими» (воркер мог упасть).
        $busy = $job->runs()->whereIn('status', ['queued', 'running'])->where('created_at', '>', now()->subHours(12))->exists();
        if ($busy) {
            return ['run' => null, 'error' => 'Это задание уже выполняется или стоит в очереди'];
        }

        $run = $job->runs()->create(['tier' => $tier, 'status' => 'queued', 'trigger' => $trigger]);
        $run->log('Поставлено в очередь', 'info');
        RunBackupJob::dispatch($run->id);

        return ['run' => $run, 'error' => null];
    }
}
