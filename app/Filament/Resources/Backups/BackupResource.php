<?php

namespace App\Filament\Resources\Backups;

use App\Filament\Resources\Backups\Pages\ListBackups;
use App\Filament\Resources\Runs\Tables\RunsTable;
use App\Models\RunTarget;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\HtmlString;

/** Список имеющихся резервных копий (успешные записи в хранилищах), удобно смотреть по хранилищам. */
class BackupResource extends Resource
{
    protected static ?string $model = RunTarget::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArchiveBox;

    protected static ?string $navigationLabel = 'Резервные копии';

    protected static ?string $modelLabel = 'копия';

    protected static ?string $pluralModelLabel = 'Резервные копии';

    protected static ?int $navigationSort = 35;

    /** id сервера записи: run_targets -> runs -> backup_jobs. */
    private const SERVER_ID_SQL = '(select backup_jobs.server_id from runs join backup_jobs on backup_jobs.id = runs.backup_job_id where runs.id = run_targets.run_id)';

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->where('status', 'success')->with(['storage', 'run.backupJob.server']);
    }

    public static function table(Table $table): Table
    {
        $tiers = ['daily' => 'дневная', 'weekly' => 'недельная', 'monthly' => 'месячная', 'manual' => 'ручная'];

        return $table
            ->columns([
                TextColumn::make('storage.name')->label('Хранилище')->sortable()->searchable(),
                TextColumn::make('run.backupJob.name')->label('Задание')->searchable(),
                TextColumn::make('run.backupJob.server.name')->label('Сервер')->searchable(),
                TextColumn::make('run.tier')->label('Тип')->badge()
                    ->formatStateUsing(fn ($state) => $tiers[$state] ?? $state),
                TextColumn::make('run.started_at')->label('Создана')->dateTime('d.m.Y H:i'),
                TextColumn::make('size_bytes')->label('Размер')->sortable()
                    ->formatStateUsing(fn ($state) => RunsTable::bytes((int) $state)),
                TextColumn::make('files')->label('Файлов')
                    ->state(fn (RunTarget $r) => count($r->objects ?? [])),
            ])
            ->filters([
                SelectFilter::make('storage_id')->label('Хранилище')->relationship('storage', 'name'),
                SelectFilter::make('server')->label('Сервер')
                    ->options(fn () => \App\Models\Server::orderBy('name')->pluck('name', 'id')->all())
                    ->query(fn (Builder $query, array $data) => filled($data['value'] ?? null)
                        ? $query->whereHas('run.backupJob', fn ($j) => $j->where('server_id', $data['value'])) : $query),
                SelectFilter::make('job')->label('Задание')
                    ->options(fn () => \App\Models\BackupJob::orderBy('name')->pluck('name', 'id')->all())
                    ->query(fn (Builder $query, array $data) => filled($data['value'] ?? null)
                        ? $query->whereHas('run', fn ($r) => $r->where('backup_job_id', $data['value'])) : $query),
                SelectFilter::make('tier')->label('Тип')->options($tiers)
                    ->query(fn (Builder $query, array $data) => filled($data['value'] ?? null)
                        ? $query->whereHas('run', fn ($r) => $r->where('tier', $data['value'])) : $query),
            ])
            ->groups([
                \Filament\Tables\Grouping\Group::make('storage.name')->label('Хранилище')->collapsible(),
                // Сервер — через запуск и задание, поэтому группировка по подзапросу.
                \Filament\Tables\Grouping\Group::make('server')->label('Сервер')->collapsible()
                    ->getKeyFromRecordUsing(fn (RunTarget $record) => (string) $record->run?->backupJob?->server_id)
                    ->getTitleFromRecordUsing(fn (RunTarget $record) => $record->run?->backupJob?->server?->name ?? 'без сервера')
                    ->groupQueryUsing(fn (\Illuminate\Database\Query\Builder $query) => $query->groupByRaw(self::SERVER_ID_SQL))
                    ->orderQueryUsing(fn (Builder $query, string $direction) => $query->orderByRaw(self::SERVER_ID_SQL.' '.($direction === 'desc' ? 'desc' : 'asc')))
                    ->scopeQueryByKeyUsing(fn (Builder $query, ?string $key) => $query->whereRaw(self::SERVER_ID_SQL.' = ?', [$key])),
            ])
            ->recordActions([
                Action::make('files')->label('Файлы')->icon('heroicon-o-document-text')
                    ->modalHeading(fn (?RunTarget $record) => $record
                        ? 'Копия от '.optional($record->run?->started_at)->format('d.m.Y H:i').' · '.$record->storage?->name
                        : 'Файлы копии')
                    ->modalSubmitAction(false)->modalCancelActionLabel('Закрыть')
                    ->modalContent(fn (?RunTarget $record) => new HtmlString($record ? self::filesHtml($record) : '')),
            ])
            ->defaultSort('run_id', 'desc');
    }

    private static function filesHtml(RunTarget $r): string
    {
        $rows = '';
        foreach ($r->objects ?? [] as $i => $o) {
            $url = route('filament.admin.backups.download', ['target' => $r->id, 'index' => $i]);
            $rows .= '<tr><td style="padding:.25rem .5rem;word-break:break-all">'.e($o['key']).'</td>'
                .'<td style="padding:.25rem .5rem;white-space:nowrap;text-align:right">'.e(RunsTable::bytes((int) ($o['size'] ?? 0))).'</td>'
                .'<td style="padding:.25rem .5rem;white-space:nowrap;text-align:right"><a href="'.e($url).'" target="_blank" rel="noopener" style="color:#d97706;font-weight:600;text-decoration:underline">Скачать</a></td></tr>';
        }

        return '<table style="width:100%;font-size:.85rem"><thead><tr style="text-align:left;opacity:.7"><th style="padding:.25rem .5rem">Файл в хранилище</th><th style="padding:.25rem .5rem;text-align:right">Размер</th><th></th></tr></thead><tbody>'
            .($rows ?: '<tr><td colspan="3" style="padding:.5rem">Нет данных о файлах</td></tr>').'</tbody></table>';
    }

    public static function getPages(): array
    {
        return ['index' => ListBackups::route('/')];
    }
}
