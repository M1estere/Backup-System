<?php

namespace App\Filament\Resources\Storages\Pages;

use App\Filament\Resources\Storages\Schemas\StorageForm;
use App\Filament\Resources\Storages\StorageResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditStorage extends EditRecord
{
    protected static string $resource = StorageResource::class;

    protected function getHeaderActions(): array
    {
        return [DeleteAction::make()
            ->failureNotificationTitle('Нельзя удалить: хранилище используется в заданиях. Сначала уберите его оттуда.')];
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        // config скрыт в модели (hidden), поэтому подставляем вручную, но без секретов и служебного host_key
        $data['config'] = collect($this->record->config ?? [])->except([...StorageForm::SECRETS, 'host_key'])->all();

        return $data;
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $old = $this->record->config ?? [];
        $new = $data['config'] ?? [];

        // Пустой секрет = не менять
        foreach (StorageForm::SECRETS as $f) {
            if (($new[$f] ?? '') === '' && isset($old[$f])) {
                $new[$f] = $old[$f];
            }
        }
        // Запомненный ключ SSH-сервера (TOFU) переносим, пока адрес/порт те же
        if (isset($old['host_key'])) {
            $sameHost = ($new['host'] ?? null) === ($old['host'] ?? null) && (int) ($new['port'] ?? 22) === (int) ($old['port'] ?? 22);
            if ($sameHost) {
                $new['host_key'] = $old['host_key'];
            }
        }
        $data['config'] = $new;
        $data['status'] = 'unknown';

        return $data;
    }
}
