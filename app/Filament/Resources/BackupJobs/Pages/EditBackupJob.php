<?php

namespace App\Filament\Resources\BackupJobs\Pages;

use App\Filament\Resources\BackupJobs\BackupJobResource;
use App\Filament\Resources\BackupJobs\RunNowAction;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditBackupJob extends EditRecord
{
    protected static string $resource = BackupJobResource::class;

    protected function getHeaderActions(): array
    {
        return [RunNowAction::make(), DeleteAction::make()];
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        // db_config скрыт в модели (hidden): подставляем без пароля
        $data['db_config'] = collect($this->record->db_config ?? [])->except('password')->all();

        return $data;
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        // Пустой пароль БД = не менять
        $old = $this->record->db_config ?? [];
        if (($data['db_config']['password'] ?? '') === '' && isset($old['password'])) {
            $data['db_config']['password'] = $old['password'];
        }

        return $data;
    }
}
