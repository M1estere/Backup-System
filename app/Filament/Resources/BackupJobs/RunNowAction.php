<?php

namespace App\Filament\Resources\BackupJobs;

use App\Filament\Resources\Runs\RunResource;
use App\Models\BackupJob;
use App\Services\Backup\BackupLauncher;
use Filament\Actions\Action;
use Filament\Notifications\Notification;

class RunNowAction
{
    public static function make(): Action
    {
        return Action::make('runNow')
            ->label('Запустить')
            ->icon('heroicon-o-play')
            ->color('success')
            ->requiresConfirmation()
            ->modalHeading(fn (BackupJob $record) => "Запустить «{$record->name}» сейчас?")
            ->modalDescription('Бэкап начнётся немедленно и запишется во все хранилища задания. Ход выполнения виден в журнале запусков.')
            ->modalSubmitActionLabel('Запустить')
            ->action(function (BackupJob $record) {
                $r = app(BackupLauncher::class)->launch($record);
                if ($r['error']) {
                    Notification::make()->title('Не удалось запустить')->body($r['error'])->danger()->send();

                    return;
                }
                Notification::make()->title('Запуск поставлен в очередь')->body("«{$record->name}»")->success()
                    ->actions([
                        Action::make('open')->label('Открыть журнал')->url(RunResource::getUrl('view', ['record' => $r['run']]))->button(),
                    ])->send();
            });
    }
}
