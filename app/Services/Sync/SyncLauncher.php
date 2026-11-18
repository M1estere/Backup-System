<?php

namespace App\Services\Sync;

use App\Jobs\RunProjectSyncJob;
use App\Models\Project;
use App\Models\ProjectSync;
use Illuminate\Support\Facades\Cache;

class SyncLauncher
{
    /** @return array{sync: ?ProjectSync, error: ?string} */
    public function launch(Project $project, string $trigger = 'manual'): array
    {
        return Cache::lock('launch-sync-'.$project->id, 10)->block(5, function () use ($project, $trigger) {
            if ($project->mirrors()->where('enabled', true)->doesntExist()) {
                return ['sync' => null, 'error' => 'У проекта нет включённых зеркал'];
            }
            $busy = $project->syncs()->whereIn('status', ['queued', 'running'])->where('created_at', '>', now()->subHours(12))->exists();
            if ($busy) {
                return ['sync' => null, 'error' => 'Синхронизация уже идёт или стоит в очереди'];
            }
            $sync = $project->syncs()->create(['status' => 'queued', 'trigger' => $trigger]);
            $sync->addLog('Поставлено в очередь');
            RunProjectSyncJob::dispatch($sync->id);

            return ['sync' => $sync, 'error' => null];
        });
    }
}
