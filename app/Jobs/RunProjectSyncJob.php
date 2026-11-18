<?php

namespace App\Jobs;

use App\Models\ProjectSync;
use App\Services\Sync\SyncRunner;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Cache;
use Throwable;

class RunProjectSyncJob implements ShouldQueue
{
    use InteractsWithQueue, Queueable;

    public int $timeout = 0;

    public int $maxExceptions = 1;

    public function __construct(public int $syncId) {}

    public function retryUntil(): \DateTimeInterface
    {
        return now()->addHours(24);
    }

    public function handle(SyncRunner $runner): void
    {
        $sync = ProjectSync::findOrFail($this->syncId);
        if ($sync->status !== 'queued') {
            return;
        }
        // родительский сервер не нагружаем параллельно с бэкапами (тот же замок, что у заданий)
        $lock = Cache::lock('backup-server-'.$sync->project->parent_server_id, 900);
        if (! $lock->get()) {
            $this->release(30);

            return;
        }
        try {
            $runner->execute($sync, fn () => $lock->refresh(900));
        } finally {
            $lock->release();
        }
    }

    public function failed(Throwable $e): void
    {
        ProjectSync::find($this->syncId)?->update(['status' => 'failed', 'finished_at' => now(), 'error' => mb_substr($e->getMessage(), 0, 1000)]);
    }
}
