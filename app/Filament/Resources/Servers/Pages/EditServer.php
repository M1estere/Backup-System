<?php

namespace App\Filament\Resources\Servers\Pages;

use App\Filament\Resources\Servers\ServerResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditServer extends EditRecord
{
    protected static string $resource = ServerResource::class;

    protected function getHeaderActions(): array
    {
        return [DeleteAction::make()
            ->failureNotificationTitle('Нельзя удалить: на этом сервере есть задания. Сначала удалите их.')];
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        // Адрес или порт изменились — запомненный ключ SSH больше недействителен (TOFU заново).
        if ($data['host'] !== $this->record->host || (int) $data['port'] !== (int) $this->record->port) {
            $data['host_key'] = null;
            $data['status'] = 'unknown';
        }

        return $data;
    }
}
