<?php

namespace App\Filament\Resources\BackupJobs\Schemas;

use App\Models\Storage;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\TimePicker;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

class BackupJobForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Основное')->columns(2)->schema([
                TextInput::make('name')->label('Название')->required()->maxLength(255),
                Select::make('server_id')->label('Сервер-источник')
                    ->relationship('server', 'name')->required()->searchable()->preload(),
                Toggle::make('enabled')->label('Включено')->default(true),
            ]),

            Section::make('Что копировать')->schema([
                TagsInput::make('paths')->label('Папки с сайтами')->required()
                    ->placeholder('/var/www/site — Enter для добавления')
                    ->helperText('Каждый путь станет отдельным архивом.'),
                TagsInput::make('excludes')->label('Исключения')
                    ->placeholder('cache, *.log, node_modules'),
                Select::make('compression')->label('Сжатие архива')->options([
                    'gzip' => 'Обычное (gzip) — меньше размер',
                    'fast' => 'Быстрое (gzip -1) — быстрее, чуть больше',
                    'none' => 'Без сжатия — самое быстрое, для фото, видео и готовых архивов',
                ])->default('gzip')->required()
                    ->helperText('Сжатие выполняется на сервере-источнике и часто ограничивает скорость бэкапа.'),
            ]),

            Section::make('База данных MySQL')->columns(2)->schema([
                Toggle::make('db_config.enabled')->label('Делать дамп БД')->live()->columnSpanFull(),
                TextInput::make('db_config.host')->label('Хост БД')->default('localhost')
                    ->visible(fn (Get $get) => (bool) $get('db_config.enabled')),
                TextInput::make('db_config.port')->label('Порт')->numeric()->default(3306)
                    ->visible(fn (Get $get) => (bool) $get('db_config.enabled')),
                TextInput::make('db_config.username')->label('Пользователь БД')
                    ->visible(fn (Get $get) => (bool) $get('db_config.enabled'))
                    ->required(fn (Get $get) => (bool) $get('db_config.enabled')),
                TextInput::make('db_config.password')->label('Пароль БД')->password()->revealable()
                    ->visible(fn (Get $get) => (bool) $get('db_config.enabled'))
                    ->helperText(fn (string $operation) => $operation === 'edit' ? 'Оставьте пустым, чтобы не менять' : null),
                TagsInput::make('db_config.databases')->label('Базы данных')
                    ->helperText('Пусто = все базы')
                    ->visible(fn (Get $get) => (bool) $get('db_config.enabled'))
                    ->columnSpanFull(),
            ]),

            Section::make('Расписание')->columns(2)
                ->description('Бэкап делается раз в сутки. Воскресный (или выбранного дня) помечается как недельный, бэкап заданного числа месяца — как месячный.')
                ->schema([
                    TimePicker::make('run_at')->label('Время запуска')->seconds(false)->default('03:00')->required(),
                    Select::make('mode')->label('Режим')->options([
                        'stream' => 'Потоком (ничего не остаётся на сервере)',
                        'archive' => 'Архив на сервере, затем скачивание',
                    ])->default('stream')->required(),
                    Select::make('weekly_day')->label('День недельного бэкапа')->options([
                        1 => 'Понедельник', 2 => 'Вторник', 3 => 'Среда', 4 => 'Четверг',
                        5 => 'Пятница', 6 => 'Суббота', 0 => 'Воскресенье',
                    ])->default(0)->required(),
                    TextInput::make('monthly_day')->label('Число месячного бэкапа')->numeric()
                        ->minValue(1)->maxValue(28)->default(1)->required(),
                ]),

            Section::make('Куда сохранять')
                ->description('Можно выбрать несколько хранилищ. Срок хранения задаётся для каждого отдельно.')
                ->schema([
                    Repeater::make('storageLinks')->label('Хранилища')
                        ->relationship()
                        ->minItems(1)
                        ->addActionLabel('Добавить хранилище')
                        ->columns(4)
                        ->schema([
                            Select::make('storage_id')->label('Хранилище')
                                ->options(fn () => Storage::orderBy('name')->pluck('name', 'id'))
                                ->required()->distinct()->searchable(),
                            TextInput::make('keep_daily')->label('Дневных')->numeric()->default(7)->minValue(0)->required(),
                            TextInput::make('keep_weekly')->label('Недельных')->numeric()->default(4)->minValue(0)->required(),
                            TextInput::make('keep_monthly')->label('Месячных')->numeric()->default(6)->minValue(0)->required(),
                        ]),
                ]),
        ]);
    }
}
