<?php

namespace App\Filament\Resources\Servers\Schemas;

use App\Filament\Support\CreatedByInfo;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class ServerForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Подключение по SSH')->columns(2)->schema([
                TextInput::make('name')->label('Название')->required()->maxLength(255),
                TextInput::make('host')->label('Адрес (IP или домен)')->required()->maxLength(255),
                TextInput::make('port')->label('Порт')->required()->numeric()->default(22)->minValue(1)->maxValue(65535),
                TextInput::make('username')->label('Логин')->required()->default('root')->maxLength(255),
                TextInput::make('password')->label('Пароль')
                    ->password()->revealable()
                    ->required(fn (string $operation) => $operation === 'create')
                    ->dehydrated(fn ($state) => filled($state))
                    ->helperText(fn (string $operation) => $operation === 'edit' ? 'Оставьте пустым, чтобы не менять' : null)
                    ->columnSpanFull(),
                Textarea::make('notes')->label('Заметки')->rows(2)->columnSpanFull(),
            ]),
            CreatedByInfo::make(),
        ]);
    }
}
