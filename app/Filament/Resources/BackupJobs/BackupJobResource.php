<?php

namespace App\Filament\Resources\BackupJobs;

use App\Filament\Resources\BackupJobs\Pages\CreateBackupJob;
use App\Filament\Resources\BackupJobs\Pages\EditBackupJob;
use App\Filament\Resources\BackupJobs\Pages\ListBackupJobs;
use App\Filament\Resources\BackupJobs\Schemas\BackupJobForm;
use App\Filament\Resources\BackupJobs\Tables\BackupJobsTable;
use App\Models\BackupJob;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class BackupJobResource extends Resource
{
    protected static ?string $model = BackupJob::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClock;

    protected static ?string $navigationLabel = 'Задания';

    protected static ?string $modelLabel = 'задание';

    protected static ?string $pluralModelLabel = 'Задания';

    protected static ?int $navigationSort = 30;

    public static function form(Schema $schema): Schema
    {
        return BackupJobForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return BackupJobsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListBackupJobs::route('/'),
            'create' => CreateBackupJob::route('/create'),
            'edit' => EditBackupJob::route('/{record}/edit'),
        ];
    }
}
