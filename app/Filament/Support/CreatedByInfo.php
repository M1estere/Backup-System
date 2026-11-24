<?php

namespace App\Filament\Support;

use Filament\Schemas\Components\Text;
use Filament\Support\Enums\TextSize;
use Illuminate\Database\Eloquent\Model;

/** Мелкая серая строка «Добавил: имя, дата» внизу формы редактирования. */
class CreatedByInfo
{
    public static function make(): Text
    {
        return Text::make(function (?Model $record) {
            if (! $record) {
                return '';
            }
            $who = $record->creator?->name ?? 'не указано';
            $when = $record->created_at?->timezone(config('app.timezone'))->format('d.m.Y H:i');

            return 'Добавил: '.$who.($when ? ', '.$when : '');
        })->size(TextSize::ExtraSmall)->color('gray')
            ->visible(fn (?Model $record) => $record !== null && $record->exists);
    }
}
