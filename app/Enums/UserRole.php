<?php

namespace App\Enums;

/**
 * Роли пользователей панели. Пока к ролям ничего не привязано:
 * у всех пользователей одинаковые полномочия, роль только метка.
 */
enum UserRole: string
{
    case Superuser = 'superuser';
    case Admin = 'admin';
    case Operator = 'operator';
    case Viewer = 'viewer';

    public function label(): string
    {
        return match ($this) {
            self::Superuser => 'Суперпользователь',
            self::Admin => 'Администратор',
            self::Operator => 'Оператор',
            self::Viewer => 'Наблюдатель',
        };
    }

    /** @return array<string,string> */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $r) => [$r->value => $r->label()])->all();
    }
}
