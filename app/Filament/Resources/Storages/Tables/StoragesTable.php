<?php

namespace App\Filament\Resources\Storages\Tables;

use App\Models\Storage;
use App\Services\Storage\StorageManager;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ViewColumn;
use Filament\Tables\Table;

class StoragesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->withSum(['runTargets as used_bytes' => fn ($q) => $q->where('status', 'success')], 'size_bytes'))
            ->columns([
                TextColumn::make('name')->label('Название')->searchable()->sortable(),
                TextColumn::make('type')->label('Тип')->badge()
                    ->formatStateUsing(fn ($state) => Storage::TYPES[$state] ?? $state),
                ViewColumn::make('usage')->label('Занято')->view('filament.tables.storage-usage'),
                TextColumn::make('status')->label('Статус')->badge()
                    ->formatStateUsing(fn ($state) => match ($state) {'ok' => 'работает', 'error' => 'ошибка', default => 'не проверялось'})
                    ->color(fn ($state) => match ($state) {'ok' => 'success', 'error' => 'danger', default => 'gray'})
                    ->tooltip(fn (Storage $r) => $r->last_error),
                TextColumn::make('last_checked_at')->label('Проверено')->since()->sortable(),
            ])
            ->recordActions([
                Action::make('check')
                    ->label('Проверить')
                    ->icon('heroicon-o-signal')
                    ->action(function (Storage $record) {
                        $r = app(StorageManager::class)->check($record);
                        Notification::make()
                            ->title($r['ok'] ? 'Хранилище доступно' : 'Ошибка подключения')
                            ->body($r['message'])
                            ->{$r['ok'] ? 'success' : 'danger'}()
                            ->persistent(! $r['ok'])
                            ->send();
                    }),
                EditAction::make()->label('Изменить'),
            ])
            ->toolbarActions([
                BulkActionGroup::make([DeleteBulkAction::make()
                    ->failureNotificationTitle('Нельзя удалить: хранилище используется в заданиях. Сначала уберите его оттуда.')]),
            ])
            ->defaultSort('name');
    }
}
