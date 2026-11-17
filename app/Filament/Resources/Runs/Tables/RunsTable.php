<?php

namespace App\Filament\Resources\Runs\Tables;

use App\Models\Run;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class RunsTable
{
    public static function statusLabel(?string $s): string
    {
        return match ($s) {
            'success' => 'успех', 'partial' => 'частично', 'failed' => 'ошибка',
            'running' => 'идёт', 'queued' => 'в очереди', default => (string) $s,
        };
    }

    public static function statusColor(?string $s): string
    {
        return match ($s) {
            'success' => 'success', 'partial' => 'warning', 'failed' => 'danger',
            'running', 'queued' => 'info', default => 'gray',
        };
    }

    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('backupJob')->withMax('targets', 'size_bytes'))
            ->columns([
                TextColumn::make('id')->label('№')->sortable(),
                TextColumn::make('backupJob.name')->label('Задание')->searchable(),
                TextColumn::make('tier')->label('Тип')->badge()
                    ->formatStateUsing(fn ($s) => ['daily' => 'дневной', 'weekly' => 'недельный', 'monthly' => 'месячный', 'manual' => 'ручной'][$s] ?? $s),
                TextColumn::make('status')->label('Статус')->badge()
                    ->formatStateUsing(fn ($s) => self::statusLabel($s))
                    ->color(fn ($s) => self::statusColor($s)),
                TextColumn::make('trigger')->label('Запуск')
                    ->formatStateUsing(fn ($s) => $s === 'manual' ? 'вручную' : 'по расписанию'),
                TextColumn::make('started_at')->label('Начало')->dateTime('d.m.Y H:i')->sortable(),
                TextColumn::make('duration')->label('Длительность')
                    ->state(fn (Run $r) => $r->started_at && $r->finished_at
                        ? gmdate('H:i:s', $r->started_at->diffInSeconds($r->finished_at)) : '—'),
                TextColumn::make('size')->label('Размер')
                    ->state(fn (Run $r) => self::bytes((int) $r->targets_max_size_bytes)),
            ])
            ->filters([
                SelectFilter::make('status')->label('Статус')->options([
                    'success' => 'успех', 'partial' => 'частично', 'failed' => 'ошибка', 'running' => 'идёт', 'queued' => 'в очереди',
                ]),
                SelectFilter::make('backup_job_id')->label('Задание')->relationship('backupJob', 'name'),
            ])
            ->recordActions([ViewAction::make()->label('Открыть')])
            ->defaultSort('id', 'desc')
            ->poll('5s');
    }

    public static function bytes(int $b): string
    {
        if ($b <= 0) {
            return '—';
        }
        $u = ['Б', 'КБ', 'МБ', 'ГБ', 'ТБ'];
        $i = (int) floor(log($b, 1024));

        return round($b / 1024 ** $i, 1).' '.$u[$i];
    }
}
