<?php

namespace App\Filament\Resources\Users\Pages;

use App\Enums\UserRole;
use App\Filament\Resources\Users\UserResource;
use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Validation\ValidationException;

class EditUser extends EditRecord
{
    protected static string $resource = UserResource::class;

    protected function getHeaderActions(): array
    {
        return [DeleteAction::make()
            ->hidden(fn () => $this->record->id === auth()->id() || $this->record->isLastSuperuser())];
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        // Нельзя понизить последнего суперпользователя: иначе в панели не останется главного.
        if ($this->record->isLastSuperuser() && ($data['role'] ?? null) !== UserRole::Superuser->value) {
            Notification::make()->title('Нельзя снять роль с последнего суперпользователя')->danger()->send();
            throw ValidationException::withMessages(['data.role' => 'Должен остаться хотя бы один суперпользователь.']);
        }

        return $data;
    }
}
