<?php

namespace App\Filament\Pages;

use App\Models\BackupJob;
use App\Models\Project;
use BackedEnum;
use Carbon\Carbon;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;

class Schedule extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalendarDays;

    protected static ?string $navigationLabel = 'Расписание';

    protected static ?string $title = 'Расписание задач';

    protected static ?int $navigationSort = 5;

    protected string $view = 'filament.pages.schedule';

    private const DAYS = ['воскресенье', 'понедельник', 'вторник', 'среда', 'четверг', 'пятница', 'суббота'];

    private const TIER = ['daily' => 'дневная', 'weekly' => 'недельная', 'monthly' => 'месячная', 'manual' => 'ручная'];

    private const STATUS = ['success' => 'успех', 'partial' => 'частично', 'failed' => 'ошибка', 'running' => 'идёт', 'queued' => 'в очереди'];

    /** Ближайший запуск: сегодня, если время ещё впереди, иначе завтра. */
    private function nextRun(string $time): Carbon
    {
        $now = now();
        $due = $now->copy()->setTimeFromTimeString($time);

        return $due->gt($now) ? $due : $due->addDay();
    }

    private function tierFor(BackupJob $job, Carbon $date): string
    {
        $monthDay = min((int) $job->monthly_day, $date->daysInMonth);

        return $date->day === $monthDay ? 'monthly' : ($date->dayOfWeek === (int) $job->weekly_day ? 'weekly' : 'daily');
    }

    private function lastRun($runs): ?array
    {
        $r = $runs->latest('id')->first();

        return $r ? [
            'status' => self::STATUS[$r->status] ?? $r->status,
            'ok' => $r->status === 'success',
            'bad' => $r->status === 'failed',
            'at' => ($r->started_at ?? $r->created_at)->format('d.m H:i'),
        ] : null;
    }

    /** @return array{rows: list<array>} */
    public function getRows(): array
    {
        $rows = [];

        foreach (BackupJob::with(['server', 'storageLinks.storage'])->get() as $job) {
            $time = substr((string) $job->run_at, 0, 5);
            $next = $this->nextRun($job->run_at);
            $rows[] = [
                'sort' => $time,
                'time' => $time,
                'kind' => 'Бэкап',
                'name' => $job->name,
                'enabled' => (bool) $job->enabled,
                'what' => 'Сервер: '.$job->server->name.' → '.($job->storageLinks->map(fn ($l) => $l->storage->name)->implode(', ') ?: 'нет хранилищ'),
                'rule' => 'Ежедневно; в '.self::DAYS[(int) $job->weekly_day].' — недельная копия, '.$job->monthly_day.'-го числа — месячная',
                'keep' => $job->storageLinks->map(fn ($l) => "{$l->keep_daily}/{$l->keep_weekly}/{$l->keep_monthly}")->unique()->implode(', ').' (дн./нед./мес.)',
                'next' => $next->isToday() ? 'сегодня '.$next->format('H:i') : ($next->isTomorrow() ? 'завтра '.$next->format('H:i') : $next->format('d.m H:i')),
                'next_note' => self::TIER[$this->tierFor($job, $next)].' копия',
                'last' => $this->lastRun($job->runs()),
            ];
        }

        foreach (Project::with(['parentServer', 'mirrors.server'])->get() as $p) {
            $time = substr((string) $p->sync_at, 0, 5);
            $next = $this->nextRun($p->sync_at);
            $mirrors = $p->mirrors->where('enabled', true);
            $rows[] = [
                'sort' => $time,
                'time' => $time,
                'kind' => 'Зеркалирование',
                'name' => $p->name,
                'enabled' => (bool) $p->enabled,
                'what' => $p->parentServer->name.' → '.($mirrors->map(fn ($m) => $m->server->name)->implode(', ') ?: 'нет зеркал'),
                'rule' => 'Ежедневно: файлы'.($p->sync_db ? ' и базы данных' : ''),
                'keep' => '—',
                'next' => $next->isToday() ? 'сегодня '.$next->format('H:i') : ($next->isTomorrow() ? 'завтра '.$next->format('H:i') : $next->format('d.m H:i')),
                'next_note' => '',
                'last' => $this->lastRun($p->syncs()),
            ];
        }

        usort($rows, fn ($a, $b) => strcmp($a['sort'], $b['sort']));

        return [
            'rows' => $rows,
        ];
    }
}
