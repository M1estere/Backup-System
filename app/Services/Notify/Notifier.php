<?php

namespace App\Services\Notify;

use App\Models\NotificationSetting;
use App\Models\ProjectSync;
use App\Models\Run;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

class Notifier
{
    private const STATUS = ['success' => 'успешно', 'partial' => 'частично', 'failed' => 'ОШИБКА', 'running' => 'идёт', 'queued' => 'в очереди'];

    private const COLOR = ['success' => '#16a34a', 'partial' => '#d97706', 'failed' => '#dc2626'];

    private const TIER = ['daily' => 'дневная', 'weekly' => 'недельная', 'monthly' => 'месячная', 'manual' => 'ручная'];

    /** Отправить письмо; возвращает текст ошибки или null. Никогда не бросает исключений (бэкапы от почты не зависят). */
    public function send(string $subject, string $html, ?NotificationSetting $s = null): ?string
    {
        $s ??= NotificationSetting::current();
        if (! $s->smtp_host || ! $s->from_address) {
            return 'Не заданы SMTP-сервер и адрес отправителя';
        }
        $to = $s->recipients();
        if (! $to) {
            return 'Не указано ни одного получателя';
        }
        try {
            config([
                'mail.mailers.panel' => [
                    'transport' => 'smtp', 'host' => $s->smtp_host, 'port' => $s->smtp_port,
                    'scheme' => $s->smtp_security === 'ssl' ? 'smtps' : 'smtp',
                    'username' => $s->smtp_username ?: null, 'password' => $s->smtp_password ?: null,
                    'timeout' => 20, 'auto_tls' => $s->smtp_security === 'tls',
                ],
                'mail.from' => ['address' => $s->from_address, 'name' => $s->from_name ?: 'Backup Panel'],
            ]);
            Mail::purge('panel');
            Mail::mailer('panel')->html($html, fn ($m) => $m->to($to)->subject($subject));

            return null;
        } catch (Throwable $e) {
            Log::warning('Не удалось отправить письмо: '.$e->getMessage());

            return $e->getMessage();
        }
    }

    public function runFinished(Run $run): void
    {
        $this->finished($run, 'Бэкап', $run->backupJob->name ?? '—', url('/admin/runs/'.$run->id), function (Run $r) {
            $rows = [];
            foreach ($r->targets()->with('storage')->get() as $t) {
                $rows[] = [$t->storage->name ?? '—', $t->status === 'success' ? $this->bytes((int) $t->size_bytes) : ($t->error ?: $t->status)];
            }

            return array_merge([['Тип копии', self::TIER[$r->tier] ?? $r->tier]], $rows);
        });
    }

    public function syncFinished(ProjectSync $sync): void
    {
        $this->finished($sync, 'Зеркалирование', $sync->project->name ?? '—', url('/admin/project-syncs/'.$sync->id), fn () => []);
    }

    private function finished($run, string $kind, string $name, string $link, callable $extra): void
    {
        $s = NotificationSetting::current();
        if ($run->notified_at || ! in_array($run->status, ['success', 'partial', 'failed'], true)) {
            return;
        }
        $run->forceFill(['notified_at' => now()])->save(); // отметка до отправки: не слать дважды
        $wanted = $run->status === 'success' ? $s->on_success : $s->on_failure;
        if (! $wanted || ! $s->isConfigured()) {
            return;
        }

        $dur = $run->started_at && $run->finished_at ? gmdate('H:i:s', $run->started_at->diffInSeconds($run->finished_at)) : '—';
        $rows = array_merge([
            ['Задача', "$kind «{$name}»"],
            ['Статус', '<b style="color:'.(self::COLOR[$run->status] ?? '#000').'">'.self::STATUS[$run->status].'</b>'],
            ['Начало', optional($run->started_at)->format('d.m.Y H:i:s') ?? '—'],
            ['Длительность', $dur],
        ], $extra($run));
        if ($run->error) {
            $rows[] = ['Ошибка', e($run->error)];
        }
        $rows[] = ['Журнал', '<a href="'.e($link).'">открыть в панели</a>'];

        $this->send("[Backup Panel] $kind «{$name}»: ".self::STATUS[$run->status], $this->table($rows), $s);
    }

    /** Ежедневная сводка за последние 24 часа. */
    public function digest(): ?string
    {
        $s = NotificationSetting::current();
        $since = now()->subDay();
        $lines = [];
        foreach (Run::with('backupJob')->where('created_at', '>=', $since)->orderBy('id')->get() as $r) {
            $lines[] = [$r->created_at->format('d.m H:i'), 'Бэкап «'.($r->backupJob->name ?? '—').'»', self::STATUS[$r->status] ?? $r->status, $r->status];
        }
        foreach (ProjectSync::with('project')->where('created_at', '>=', $since)->orderBy('id')->get() as $r) {
            $lines[] = [$r->created_at->format('d.m H:i'), 'Зеркалирование «'.($r->project->name ?? '—').'»', self::STATUS[$r->status] ?? $r->status, $r->status];
        }
        usort($lines, fn ($a, $b) => strcmp($a[0], $b[0]));
        $bad = count(array_filter($lines, fn ($l) => in_array($l[3], ['failed', 'partial'], true)));

        $html = '<h3 style="font-family:sans-serif">Сводка за сутки</h3>';
        if (! $lines) {
            $html .= '<p style="font-family:sans-serif">За последние 24 часа запусков не было.</p>';
        } else {
            $html .= '<table cellpadding="6" style="font-family:sans-serif;font-size:14px;border-collapse:collapse">';
            foreach ($lines as $l) {
                $html .= '<tr style="border-bottom:1px solid #ddd"><td>'.$l[0].'</td><td>'.e($l[1]).'</td><td style="color:'.(self::COLOR[$l[3]] ?? '#555').'"><b>'.e($l[2]).'</b></td></tr>';
            }
            $html .= '</table>';
        }

        return $this->send('[Backup Panel] Сводка за сутки: '.($bad ? "проблем: $bad" : 'всё в порядке'), $html, $s);
    }

    private function table(array $rows): string
    {
        $h = '<table cellpadding="6" style="font-family:sans-serif;font-size:14px;border-collapse:collapse">';
        foreach ($rows as [$k, $v]) {
            $h .= '<tr style="border-bottom:1px solid #ddd"><td style="color:#666">'.e($k).'</td><td>'.$v.'</td></tr>';
        }

        return $h.'</table>';
    }

    private function bytes(int $b): string
    {
        $u = ['Б', 'КБ', 'МБ', 'ГБ', 'ТБ'];
        $i = $b > 0 ? (int) floor(log($b, 1024)) : 0;

        return round($b / 1024 ** $i, 1).' '.$u[$i];
    }
}
