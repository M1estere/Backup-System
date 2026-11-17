<?php

namespace App\Filament\Resources\Runs\Pages;

use App\Filament\Resources\Runs\RunResource;
use App\Filament\Resources\Runs\Tables\RunsTable;
use App\Models\Run;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class ViewRun extends ViewRecord
{
    protected static string $resource = RunResource::class;

    protected ?string $pollingInterval = '3s';

    public function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Запуск')->columns(4)->schema([
                TextEntry::make('backupJob.name')->label('Задание'),
                TextEntry::make('status')->label('Статус')->badge()
                    ->formatStateUsing(fn ($s) => RunsTable::statusLabel($s))
                    ->color(fn ($s) => RunsTable::statusColor($s)),
                TextEntry::make('started_at')->label('Начало')->dateTime('d.m.Y H:i:s'),
                TextEntry::make('finished_at')->label('Конец')->dateTime('d.m.Y H:i:s')->placeholder('—'),
                TextEntry::make('error')->label('Ошибка')->color('danger')->columnSpanFull()->visible(fn (Run $r) => filled($r->error)),
            ]),
            Section::make('Хранилища')->schema([
                RepeatableEntry::make('targets')->hiddenLabel()->columns(4)->schema([
                    TextEntry::make('storage.name')->label('Хранилище'),
                    TextEntry::make('status')->label('Статус')->badge()
                        ->formatStateUsing(fn ($s) => ['pending' => 'ожидает', 'running' => 'идёт', 'success' => 'успех', 'failed' => 'ошибка'][$s] ?? $s)
                        ->color(fn ($s) => ['success' => 'success', 'failed' => 'danger', 'running' => 'info'][$s] ?? 'gray'),
                    TextEntry::make('size_bytes')->label('Размер')->formatStateUsing(fn ($s) => RunsTable::bytes((int) $s)),
                    TextEntry::make('error')->label('Ошибка')->color('danger')->placeholder('—'),
                ]),
            ]),
            Section::make('Лог')->schema([
                TextEntry::make('log')->hiddenLabel()
                    ->state(fn (Run $r) => $r->logs()->orderBy('id')->get()->map(
                        fn ($l) => '['.$l->created_at->format('H:i:s').'] '
                            .($l->level !== 'info' ? strtoupper($l->level).' ' : '')
                            .($l->step ? $l->step.': ' : '').$l->message
                    )->implode("\n") ?: 'Записей пока нет')
                    ->extraAttributes(['style' => 'white-space:pre-wrap;font-family:ui-monospace,monospace;font-size:.8rem']),
            ]),
        ]);
    }
}
