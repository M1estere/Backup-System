<?php

namespace App\Filament\Resources\Servers\Tables;

use App\Models\Server;
use App\Services\Ssh\ServerChecker;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ServersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->label('Название')->searchable()->sortable(),
                TextColumn::make('host')->label('Адрес')->searchable()
                    ->formatStateUsing(fn (Server $r) => $r->host.':'.$r->port),
                TextColumn::make('username')->label('Логин'),
                TextColumn::make('status')->label('Статус')->badge()
                    ->formatStateUsing(fn ($state) => match ($state) {'ok' => 'доступен', 'error' => 'ошибка', default => 'не проверялся'})
                    ->color(fn ($state) => match ($state) {'ok' => 'success', 'error' => 'danger', default => 'gray'})
                    ->tooltip(fn (Server $r) => $r->last_error),
                TextColumn::make('capabilities.has_mysqldump')->label('mysqldump')
                    ->formatStateUsing(fn ($state) => $state === null ? '—' : ($state === '1' ? 'есть' : 'нет')),
                TextColumn::make('last_checked_at')->label('Проверен')->since()->sortable(),
            ])
            ->recordActions([
                Action::make('check')
                    ->label('Проверить')
                    ->icon('heroicon-o-signal')
                    ->action(function (Server $record) {
                        $r = (new ServerChecker)->check($record);
                        Notification::make()
                            ->title($r['ok'] ? 'Сервер доступен' : 'Ошибка подключения')
                            ->body($r['message'])
                            ->{$r['ok'] ? 'success' : 'danger'}()
                            ->persistent(! $r['ok'])
                            ->send();
                    }),
                EditAction::make()->label('Изменить'),
            ])
            ->toolbarActions([
                BulkActionGroup::make([DeleteBulkAction::make()
                    ->failureNotificationTitle('Нельзя удалить: на этом сервере есть задания. Сначала удалите их.')]),
            ])
            ->defaultSort('name');
    }
}
