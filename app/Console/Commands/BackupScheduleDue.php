<?php

namespace App\Console\Commands;

use App\Models\BackupJob;
use App\Services\Backup\BackupLauncher;
use Illuminate\Console\Command;

/**
 * Каждую минуту: запускает задания, у которых наступило время (раз в сутки, окно — час после run_at).
 * Тип копии: месячная в «число месяца», иначе недельная в «день недели», иначе дневная.
 */
class BackupScheduleDue extends Command
{
    protected $signature = 'backup:schedule-due';

    protected $description = 'Запустить по расписанию бэкапы (дневные, недельные, месячные)';

    public function handle(BackupLauncher $launcher): int
    {
        $now = now();
        foreach (BackupJob::where('enabled', true)->get() as $job) {
            $due = $now->copy()->setTimeFromTimeString($job->run_at);
            if ($now->lt($due) || $due->diffInMinutes($now) > 59) {
                continue;
            }
            if ($job->runs()->where('trigger', 'schedule')->where('created_at', '>=', $due)->exists()) {
                continue;
            }
            $monthDay = min((int) $job->monthly_day, $now->daysInMonth);
            $tier = $now->day === $monthDay ? 'monthly' : ($now->dayOfWeek === (int) $job->weekly_day ? 'weekly' : 'daily');
            $r = $launcher->launch($job, 'schedule', $tier);
            $this->info($job->name.' ['.$tier.']: '.($r['error'] ?? 'поставлено в очередь'));
        }

        return self::SUCCESS;
    }
}
