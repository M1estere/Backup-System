<?php

namespace App\Filament\Resources\ProjectSyncs;

use App\Filament\Resources\ProjectSyncs\Pages\ListProjectSyncs;
use App\Filament\Resources\ProjectSyncs\Pages\ViewProjectSync;
use App\Filament\Resources\Runs\Tables\RunsTable;
use App\Models\ProjectSync;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ProjectSyncResource extends Resource
{
    protected static ?string $model = ProjectSync::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowPath;

    protected static ?string $navigationLabel = 'Журнал синхронизаций';

    protected static ?string $modelLabel = 'синхронизация';

    protected static ?string $pluralModelLabel = 'Журнал синхронизаций';

    protected static ?int $navigationSort = 45;

    public static function canCreate(): bool
    {
        return false;
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Синхронизация')->columns(4)->schema([
                TextEntry::make('project.name')->label('Проект'),
                TextEntry::make('status')->label('Статус')->badge()
                    ->formatStateUsing(fn ($state) => RunsTable::statusLabel($state))->color(fn ($state) => RunsTable::statusColor($state)),
                TextEntry::make('started_at')->label('Начало')->dateTime('d.m.Y H:i:s')->placeholder('—'),
                TextEntry::make('finished_at')->label('Конец')->dateTime('d.m.Y H:i:s')->placeholder('—'),
                TextEntry::make('error')->label('Ошибка')->color('danger')->columnSpanFull()->visible(fn (ProjectSync $r) => filled($r->error)),
            ]),
            Section::make('Лог')->schema([
                TextEntry::make('log')->hiddenLabel()->placeholder('Записей пока нет')
                    ->extraAttributes(['style' => 'white-space:pre-wrap;font-family:ui-monospace,monospace;font-size:.8rem']),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('project'))
            ->columns([
                TextColumn::make('id')->label('№')->sortable(),
                TextColumn::make('project.name')->label('Проект')->searchable(),
                TextColumn::make('status')->label('Статус')->badge()
                    ->formatStateUsing(fn ($state) => RunsTable::statusLabel($state))->color(fn ($state) => RunsTable::statusColor($state)),
                TextColumn::make('trigger')->label('Запуск')->formatStateUsing(fn ($state) => $state === 'manual' ? 'вручную' : 'по расписанию'),
                TextColumn::make('started_at')->label('Начало')->dateTime('d.m.Y H:i')->sortable(),
                TextColumn::make('finished_at')->label('Конец')->dateTime('d.m.Y H:i'),
            ])
            ->recordActions([ViewAction::make()->label('Открыть')])
            ->defaultSort('id', 'desc')->poll('5s');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListProjectSyncs::route('/'),
            'view' => ViewProjectSync::route('/{record}'),
        ];
    }
}
