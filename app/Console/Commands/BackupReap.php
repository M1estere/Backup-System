<?php

namespace App\Console\Commands;

use App\Models\Run;
use Illuminate\Console\Command;

/** Сторож: помечает как неудачные запуски, чей воркер перестал подавать «пульс» (упал, перезапущен, убит по памяти). */
class BackupReap extends Command
{
    protected $signature = 'backup:reap';

    protected $description = 'Пометить зависшие запуски бэкапов как неудачные';

    public function handle(): int
    {
        $n = 0;
        $stale = Run::where('status', 'running')->where('updated_at', '<', now()->subMinutes((int) config('backup.stale_minutes', 30)))->get();
        foreach ($stale as $run) {
            $this->markFailed($run, 'Воркер перестал отвечать (процесс завершён аварийно или перезапущен)');
            $n++;
        }
        // задача так и не была взята в работу (очередь потеряна)
        foreach (Run::where('status', 'queued')->where('created_at', '<', now()->subHours(12))->get() as $run) {
            $this->markFailed($run, 'Запуск не был взят в работу в течение 12 часов');
            $n++;
        }
        if ($n) {
            $this->info("Помечено зависших запусков: $n");
        }

        return self::SUCCESS;
    }

    private function markFailed(Run $run, string $why): void
    {
        $run->targets()->whereIn('status', ['pending', 'running'])->update(['status' => 'failed', 'error' => $why]);
        $run->log($why, 'error');
        $run->update(['status' => 'failed', 'finished_at' => now(), 'error' => $why]);
        app(\App\Services\Notify\Notifier::class)->runFinished($run->fresh());
    }
}
