<?php

namespace App\Filament\Pages;

use App\Models\NotificationSetting;
use App\Services\Notify\Notifier;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\TimePicker;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

class Notifications extends Page implements HasSchemas
{
    use InteractsWithSchemas;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedEnvelope;

    protected static ?string $navigationLabel = 'Уведомления';

    protected static ?string $title = 'Уведомления на почту';

    protected static ?int $navigationSort = 50;

    protected string $view = 'filament.pages.notifications';

    public ?array $data = [];

    public function mount(): void
    {
        $this->form->fill(NotificationSetting::current()->only([
            'emails', 'on_failure', 'on_success', 'digest_enabled', 'digest_time',
            'smtp_host', 'smtp_port', 'smtp_security', 'smtp_username', 'from_address', 'from_name',
        ]));
    }

    public function form(Schema $schema): Schema
    {
        return $schema->statePath('data')->components([
            Section::make('Кому и о чём писать')->columns(2)->schema([
                Textarea::make('emails')->label('Получатели (по одному адресу на строку)')->rows(3)->columnSpanFull(),
                Toggle::make('on_failure')->label('Сразу сообщать об ошибках и частичных сбоях'),
                Toggle::make('on_success')->label('Сообщать о каждом успешном запуске'),
                Toggle::make('digest_enabled')->label('Ежедневная сводка за сутки')->live(),
                TimePicker::make('digest_time')->label('Время сводки (МСК)')->seconds(false)
                    ->visible(fn ($get) => (bool) $get('digest_enabled')),
            ]),
            Section::make('Почтовый сервер (SMTP)')->columns(2)->schema([
                TextInput::make('smtp_host')->label('Сервер')->placeholder('smtp.yandex.ru'),
                TextInput::make('smtp_port')->label('Порт')->numeric()->default(587),
                Select::make('smtp_security')->label('Шифрование')->options([
                    'tls' => 'STARTTLS (порт 587)', 'ssl' => 'SSL/TLS (порт 465)', 'none' => 'Без шифрования',
                ])->default('tls'),
                TextInput::make('smtp_username')->label('Логин'),
                TextInput::make('smtp_password')->label('Пароль')->password()->revealable()
                    ->helperText('Пусто = не менять. Хранится в базе в зашифрованном виде.'),
                TextInput::make('from_address')->label('Адрес отправителя')->email(),
                TextInput::make('from_name')->label('Имя отправителя')->default('Backup Panel'),
            ]),
        ]);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('test')->label('Отправить тестовое письмо')->icon('heroicon-o-paper-airplane')->color('gray')
                ->action(function () {
                    $this->persist();
                    $err = app(Notifier::class)->send(
                        '[Backup Panel] Тестовое письмо',
                        '<p style="font-family:sans-serif">Если вы читаете это письмо, уведомления настроены верно.</p>',
                    );
                    $err
                        ? Notification::make()->title('Не удалось отправить')->body($err)->danger()->send()
                        : Notification::make()->title('Тестовое письмо отправлено')->success()->send();
                }),
        ];
    }

    public function save(): void
    {
        $this->persist();
        Notification::make()->title('Настройки сохранены')->success()->send();
    }

    private function persist(): void
    {
        $data = $this->form->getState();
        if (blank($data['smtp_password'] ?? null)) {
            unset($data['smtp_password']);
        }
        NotificationSetting::current()->update($data);
    }
}
