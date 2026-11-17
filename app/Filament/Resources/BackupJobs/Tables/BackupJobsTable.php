<?php

namespace App\Filament\Resources\BackupJobs\Tables;

use App\Filament\Resources\BackupJobs\RunNowAction;
use App\Models\BackupJob;
use App\Models\Run;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class BackupJobsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['server', 'storageLinks.storage'])->addSelect([
                'last_status' => Run::select('status')->whereColumn('backup_job_id', 'backup_jobs.id')->latest('id')->limit(1),
            ]))
            ->columns([
                TextColumn::make('name')->label('Название')->searchable()->sortable(),
                TextColumn::make('server.name')->label('Сервер')->sortable(),
                TextColumn::make('storageLinks.storage.name')->label('Хранилища')->badge(),
                TextColumn::make('run_at')->label('Время')->time('H:i'),
                TextColumn::make('last_status')->label('Последний запуск')->badge()
                    ->state(fn (BackupJob $r) => $r->last_status)
                    ->formatStateUsing(fn ($state) => match ($state) {
                        'success' => 'успех', 'partial' => 'частично', 'failed' => 'ошибка',
                        'running' => 'идёт', 'queued' => 'в очереди', default => 'ещё не было',
                    })
                    ->color(fn ($state) => match ($state) {
                        'success' => 'success', 'partial' => 'warning', 'failed' => 'danger',
                        'running', 'queued' => 'info', default => 'gray',
                    }),
                IconColumn::make('enabled')->label('Вкл.')->boolean(),
            ])
            ->recordActions([RunNowAction::make(), EditAction::make()->label('Изменить')])
            ->toolbarActions([BulkActionGroup::make([DeleteBulkAction::make()])])
            ->defaultSort('name');
    }
}
