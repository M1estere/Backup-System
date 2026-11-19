<?php

namespace App\Console\Commands;

use App\Models\NotificationSetting;
use App\Services\Notify\Notifier;
use Illuminate\Console\Command;

/** Каждую минуту: в назначенное время раз в сутки отправляет сводку на почту. */
class NotifyDigest extends Command
{
    protected $signature = 'notify:digest {--force : отправить сейчас, не дожидаясь времени}';

    protected $description = 'Ежедневная сводка по бэкапам на почту';

    public function handle(Notifier $notifier): int
    {
        $s = NotificationSetting::current();
        if (! $this->option('force')) {
            $due = now()->setTimeFromTimeString($s->digest_time);
            if (! $s->digest_enabled || ! $s->isConfigured() || now()->lt($due) || $due->diffInMinutes(now()) > 59
                || $s->last_digest_on?->isSameDay(now())) {
                return self::SUCCESS;
            }
        }
        $s->update(['last_digest_on' => now()->toDateString()]);
        $err = $notifier->digest();
        $this->info($err ?? 'Сводка отправлена');

        return $err ? self::FAILURE : self::SUCCESS;
    }
}
