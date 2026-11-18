<?php

namespace App\Console\Commands;

use App\Models\Project;
use App\Services\Sync\SyncLauncher;
use Illuminate\Console\Command;

/** Каждую минуту: запускает синхронизацию проектов, у которых наступило время (раз в сутки). */
class ProjectsSyncDue extends Command
{
    protected $signature = 'projects:sync-due';

    protected $description = 'Запустить по расписанию синхронизацию проектов с зеркалами';

    public function handle(SyncLauncher $launcher): int
    {
        $now = now();
        foreach (Project::where('enabled', true)->get() as $p) {
            $due = $now->copy()->setTimeFromTimeString($p->sync_at);
            if ($now->lt($due) || $now->diffInMinutes($due) > 59) {
                continue; // окно запуска: час после назначенного времени (если панель была недоступна)
            }
            if ($p->syncs()->where('trigger', 'schedule')->where('created_at', '>=', $due)->exists()) {
                continue; // сегодня уже запускали
            }
            $r = $launcher->launch($p, 'schedule');
            $this->info($p->name.': '.($r['error'] ?? 'поставлено в очередь'));
        }

        return self::SUCCESS;
    }
}
