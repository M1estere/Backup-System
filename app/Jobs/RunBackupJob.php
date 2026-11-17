<?php

namespace App\Jobs;

use App\Models\Run;
use App\Services\Backup\BackupRunner;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Cache;
use Throwable;

class RunBackupJob implements ShouldQueue
{
    use InteractsWithQueue, Queueable;

    public int $timeout = 0;

    /** Любое необработанное исключение — сразу «failed», без автоповтора бэкапа. */
    public int $maxExceptions = 1;

    public function __construct(public int $runId) {}

    /** Ожидание занятого сервера возвращает задачу в очередь (release), поэтому лимит попыток заменён лимитом времени. */
    public function retryUntil(): \DateTimeInterface
    {
        return now()->addHours(24);
    }

    public function handle(BackupRunner $runner): void
    {
        $run = Run::findOrFail($this->runId);
        if ($run->status !== 'queued') {
            return; // уже выполнен или снят сторожем — второй раз не запускаем
        }

        // На одном сервере-источнике одновременно идёт не больше одного бэкапа (не нагружаем прод).
        $lock = Cache::lock('backup-server-'.$run->backupJob->server_id, 900);
        if (! $lock->get()) {
            if (! $run->logs()->where('step', 'wait')->exists()) {
                $run->log('На этом сервере уже идёт другой бэкап, ожидаем очереди', 'info', 'wait');
            }
            $this->release(30);

            return;
        }

        try {
            // пульс продлевает блокировку: если воркер упадёт, она истечёт сама
            $runner->execute($run, fn () => $lock->refresh(900));
        } finally {
            $lock->release();
        }
    }

    public function failed(Throwable $e): void
    {
        Run::find($this->runId)?->update([
            'status' => 'failed', 'finished_at' => now(), 'error' => mb_substr($e->getMessage(), 0, 1000),
        ]);
    }
}
