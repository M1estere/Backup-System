<?php

namespace App\Filament\Resources\Storages;

use App\Filament\Resources\Storages\Pages\CreateStorage;
use App\Filament\Resources\Storages\Pages\EditStorage;
use App\Filament\Resources\Storages\Pages\ListStorages;
use App\Filament\Resources\Storages\Schemas\StorageForm;
use App\Filament\Resources\Storages\Tables\StoragesTable;
use App\Models\Storage;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class StorageResource extends Resource
{
    protected static ?string $model = Storage::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCloud;

    protected static ?string $navigationLabel = 'Хранилища';

    protected static ?string $modelLabel = 'хранилище';

    protected static ?string $pluralModelLabel = 'Хранилища';

    protected static ?int $navigationSort = 20;

    public static function form(Schema $schema): Schema
    {
        return StorageForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return StoragesTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListStorages::route('/'),
            'create' => CreateStorage::route('/create'),
            'edit' => EditStorage::route('/{record}/edit'),
        ];
    }
}
