<?php

namespace App\Filament\Resources\Users\Schemas;

use App\Enums\UserRole;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Validation\Rules\Password;

class UserForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Пользователь')->columns(2)->schema([
                TextInput::make('name')->label('Имя')->required()->maxLength(255),
                TextInput::make('email')->label('Email (логин)')->email()->required()->maxLength(255)
                    ->unique(ignoreRecord: true),
                Select::make('role')->label('Роль')->options(UserRole::options())
                    ->default(UserRole::Admin->value)->required()->native(false)
                    ->helperText('Пока роли ни на что не влияют: у всех одинаковые права.'),
                TextInput::make('password')->label('Пароль')
                    ->password()->revealable()
                    ->rule(Password::min(8))
                    ->required(fn (string $operation) => $operation === 'create')
                    ->dehydrated(fn ($state) => filled($state))
                    ->helperText(fn (string $operation) => $operation === 'edit' ? 'Оставьте пустым, чтобы не менять' : 'Не короче 8 символов'),
            ]),
        ]);
    }
}
