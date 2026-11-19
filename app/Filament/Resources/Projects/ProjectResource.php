<?php

namespace App\Filament\Resources\Projects;

use App\Filament\Resources\ProjectSyncs\ProjectSyncResource;
use App\Filament\Resources\Projects\Pages\CreateProject;
use App\Filament\Resources\Projects\Pages\EditProject;
use App\Filament\Resources\Projects\Pages\ListProjects;
use App\Models\Project;
use App\Services\Sync\SyncLauncher;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TimePicker;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ProjectResource extends Resource
{
    protected static ?string $model = Project::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSquare2Stack;

    protected static ?string $navigationLabel = 'Проекты и зеркала';

    protected static ?string $modelLabel = 'проект';

    protected static ?string $pluralModelLabel = 'Проекты и зеркала';

    protected static ?int $navigationSort = 25;

    public static function runAction(): Action
    {
        return Action::make('syncNow')
            ->label('Синхронизировать')
            ->icon('heroicon-o-arrow-path')
            ->color('success')
            ->requiresConfirmation()
            ->modalHeading(fn (Project $record) => "Синхронизировать «{$record->name}» сейчас?")
            ->modalDescription('Код с родительского сервера будет скопирован на все включённые зеркала (файлы на зеркалах перезапишутся).')
            ->action(function (Project $record) {
                $r = app(SyncLauncher::class)->launch($record);
                if ($r['error']) {
                    Notification::make()->title('Не удалось запустить')->body($r['error'])->danger()->send();

                    return;
                }
                Notification::make()->title('Синхронизация поставлена в очередь')->success()
                    ->actions([Action::make('open')->label('Открыть журнал')->url(ProjectSyncResource::getUrl('view', ['record' => $r['sync']]))->button()])->send();
            });
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Проект')->columns(2)->schema([
                TextInput::make('name')->label('Название')->required()->maxLength(255),
                Select::make('parent_server_id')->label('Родительский сервер (источник кода)')
                    ->relationship('parentServer', 'name')->required()->searchable()->preload(),
                TextInput::make('source_path')->label('Папка с кодом на родителе')->required()->placeholder('/home/bitrix/www')->maxLength(500),
                TimePicker::make('sync_at')->label('Ежедневно в (МСК)')->seconds(false)->default('03:00')->required(),
                Textarea::make('excludes')->label('Не копировать (по одному шаблону на строку)')->rows(3)
                    ->placeholder("upload\nbitrix/cache\nbitrix/php_interface/dbconn.php")
                    ->helperText('Общие исключения для всех зеркал: кэш, загрузки, конфиги с реквизитами БД.')->columnSpanFull(),
                Textarea::make('extra_paths')->label('Дополнительные папки (по одной на строку, абсолютные пути)')->rows(2)
                    ->placeholder("/var/www/user-data\n/var/www/CodeIgniter-2.1.0")
                    ->helperText('Копируются на зеркала в те же пути (например, загрузки и фреймворк, лежащие вне основной папки).')->columnSpanFull(),
                Toggle::make('enabled')->label('Синхронизация включена')->default(true),
                Toggle::make('sync_db')->label('Синхронизировать и базы данных')->live(),
            ]),
            Section::make('Базы данных на родителе')->columns(2)->visible(fn ($get) => (bool) $get('sync_db'))->schema([
                TextInput::make('db_host')->label('Хост БД')->default('localhost'),
                TextInput::make('db_port')->label('Порт')->numeric()->default(3306),
                TextInput::make('db_user')->label('Пользователь БД')->required(fn ($get) => (bool) $get('sync_db')),
                TextInput::make('db_password')->label('Пароль БД')->password()->revealable()
                    ->dehydrated(fn ($state) => filled($state))->helperText('Пусто = без пароля / не менять'),
                Textarea::make('db_names')->label('Базы (по одной на строку)')->rows(3)->required(fn ($get) => (bool) $get('sync_db'))->columnSpanFull()
                    ->helperText('Каждая база на зеркале будет перезаписана данными с родителя при каждой синхронизации.'),
            ]),
            Section::make('Зеркала')->schema([
                Repeater::make('mirrors')->relationship('mirrors')->hiddenLabel()->addActionLabel('Добавить зеркало')
                    ->itemLabel(fn (array $state) => $state['target_path'] ?? 'Зеркало')->collapsible()->columns(2)->schema([
                        Select::make('server_id')->label('Сервер-зеркало')->relationship('server', 'name')->required()->searchable()->preload(),
                        TextInput::make('target_path')->label('Папка на зеркале')->required()->maxLength(500),
                        TextInput::make('docroot')->label('Подпапка веб-корня на зеркале')->placeholder('www')->helperText('Куда класть robots.txt (если сайт отдаётся не из корня папки).'),
                        Textarea::make('excludes')->label('Доп. исключения только для этого зеркала')->rows(2)->columnSpanFull(),
                        TextInput::make('chown')->label('Владелец файлов (user:group)')->placeholder('bitrix:bitrix')->helperText('Применяется после копирования; пусто = владелец ssh-пользователя.'),
                        TextInput::make('db_host')->label('БД зеркала: хост')->default('localhost'),
                        TextInput::make('db_port')->label('БД зеркала: порт')->numeric()->default(3306),
                        TextInput::make('db_user')->label('БД зеркала: пользователь')->placeholder('root'),
                        TextInput::make('db_password')->label('БД зеркала: пароль')->password()->revealable()->dehydrated(fn ($state) => filled($state))
                            ->helperText('Нужно только если включена синхронизация БД; пусто = без пароля / не менять'),
                        Toggle::make('enabled')->label('Зеркало включено')->default(true)->inline(false),
                        Toggle::make('noindex')->label('Закрыть от индексации')->default(true)
                            ->helperText('robots.txt «Disallow: /» и заголовок X-Robots-Tag; восстанавливается после каждой синхронизации.'),
                        Toggle::make('basic_auth')->label('Закрыть Basic auth')->live()
                            ->helperText('Apache/.htaccess. Для nginx правило нужно задать на сервере вручную.'),
                        TextInput::make('basic_auth_user')->label('Логин Basic auth')->visible(fn ($get) => $get('basic_auth'))->required(fn ($get) => (bool) $get('basic_auth')),
                        TextInput::make('basic_auth_password')->label('Пароль Basic auth')->password()->revealable()
                            ->visible(fn ($get) => $get('basic_auth'))
                            ->dehydrated(fn ($state) => filled($state))
                            ->helperText('Пустое поле при редактировании = не менять'),
                    ]),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('parentServer')->withCount('mirrors'))
            ->columns([
                TextColumn::make('name')->label('Название')->searchable()->sortable(),
                TextColumn::make('parentServer.name')->label('Родитель'),
                TextColumn::make('mirrors_count')->label('Зеркал')->badge(),
                TextColumn::make('sync_at')->label('Время')->time('H:i'),
                IconColumn::make('enabled')->label('Вкл.')->boolean(),
            ])
            ->recordActions([self::runAction(), EditAction::make()->label('Изменить')])
            ->toolbarActions([BulkActionGroup::make([DeleteBulkAction::make()])])
            ->defaultSort('name');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListProjects::route('/'),
            'create' => CreateProject::route('/create'),
            'edit' => EditProject::route('/{record}/edit'),
        ];
    }
}
