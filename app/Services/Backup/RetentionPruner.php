<?php

namespace App\Services\Backup;

use App\Models\BackupJob;
use App\Models\RunTarget;
use App\Services\Storage\StorageManager;
use Throwable;

/**
 * Ретенция: для каждого хранилища задания и каждого типа (дневной/недельный/месячный) оставляем N последних
 * успешных копий, остальные удаляются из хранилища. Ручные запуски не удаляются.
 */
class RetentionPruner
{
    public function __construct(private StorageManager $storages) {}

    /** @return int число удалённых копий */
    public function prune(BackupJob $job): int
    {
        $removed = 0;
        foreach ($job->storageLinks()->with('storage')->get() as $link) {
            foreach (['daily' => $link->keep_daily, 'weekly' => $link->keep_weekly, 'monthly' => $link->keep_monthly] as $tier => $keep) {
                $targets = RunTarget::query()
                    ->where('storage_id', $link->storage_id)->where('status', 'success')
                    ->whereHas('run', fn ($q) => $q->where('backup_job_id', $job->id)->where('tier', $tier))
                    ->orderByDesc('run_id')->get();

                foreach ($targets->slice((int) $keep) as $t) {
                    if ($this->delete($link->storage, $t)) {
                        $removed++;
                    }
                }
            }
        }

        return $removed;
    }

    private function delete($storage, RunTarget $t): bool
    {
        $objects = $t->objects ?? [];
        if (! $objects) {
            return false;
        }
        try {
            $driver = $this->storages->driver($storage);
            foreach ($objects as $o) {
                $driver->delete($o['key']);
            }
            // манифест лежит рядом: <папка>/<дата_время>.manifest.json
            $first = $objects[0]['key'];
            $stamp = substr(basename($first), 0, 19);
            $driver->delete(dirname($first).'/'.$stamp.'.manifest.json');
        } catch (Throwable $e) {
            $t->run->log("Ретенция: не удалось удалить копию из «{$storage->name}»: ".$e->getMessage(), 'warning');

            return false;
        }
        $t->update(['status' => 'pruned', 'objects' => null]);
        $t->run->log("Ретенция: старая копия удалена из «{$storage->name}»", 'info');

        return true;
    }
}
