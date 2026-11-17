<?php

namespace App\Filament\Resources\Storages\Schemas;

use App\Models\Storage;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

class StorageForm
{
    /** Секретные поля: при редактировании пустое значение = «не менять». */
    public const SECRETS = ['secret', 'password'];

    public static function configure(Schema $schema): Schema
    {
        $secret = fn (string $label) => TextInput::make('config.'.($label === 'Secret Key' ? 'secret' : 'password'))
            ->label($label)->password()->revealable()
            ->required(fn (string $operation) => $operation === 'create')
            ->helperText(fn (string $operation) => $operation === 'edit' ? 'Оставьте пустым, чтобы не менять' : null);

        return $schema->components([
            Section::make('Хранилище')->columns(2)->schema([
                TextInput::make('name')->label('Название')->required()->maxLength(255),
                Select::make('type')->label('Тип')->options(Storage::TYPES)->required()->live()
                    ->default(Storage::TYPE_S3)
                    ->disabled(fn (string $operation) => $operation === 'edit')
                    ->dehydrated(),
            ]),

            Section::make('Amazon S3')->columns(2)
                ->visible(fn (Get $get) => $get('type') === Storage::TYPE_S3)
                ->schema([
                    TextInput::make('config.bucket')->label('Бакет')->required(),
                    TextInput::make('config.region')->label('Регион')->default('us-east-1')->required(),
                    TextInput::make('config.key')->label('Access Key ID')->required(),
                    $secret('Secret Key'),
                    TextInput::make('config.prefix')->label('Префикс (папка в бакете)')->placeholder('backups'),
                    TextInput::make('config.storage_class')->label('Класс хранения')->placeholder('STANDARD, STANDARD_IA…'),
                    TextInput::make('config.endpoint')->label('Endpoint (только для S3-совместимых)')->placeholder('оставьте пустым для Amazon'),
                    Toggle::make('config.path_style')->label('Path-style адресация')->inline(false),
                ]),

            Section::make('Windows / SFTP')->columns(2)
                ->description('На Windows-машине должен быть включён OpenSSH Server.')
                ->visible(fn (Get $get) => $get('type') === Storage::TYPE_SFTP)
                ->schema([
                    TextInput::make('config.host')->label('Адрес')->required(),
                    TextInput::make('config.port')->label('Порт')->numeric()->default(22)->required(),
                    TextInput::make('config.username')->label('Логин')->required(),
                    $secret('Пароль'),
                    TextInput::make('config.base_path')->label('Папка для бэкапов')->default('/backups')->required()
                        ->helperText('Путь на сервере SFTP, например /backups или /C:/Backups')->columnSpanFull(),
                ]),

            Section::make('Локальная папка')
                ->description('Папка на диске сервера панели (для тестов или смонтированного диска).')
                ->visible(fn (Get $get) => $get('type') === Storage::TYPE_LOCAL)
                ->schema([
                    TextInput::make('config.path')->label('Путь')->required()->placeholder('/var/backups'),
                ]),

            Textarea::make('notes')->label('Заметки')->rows(2)->columnSpanFull(),
        ]);
    }
}
