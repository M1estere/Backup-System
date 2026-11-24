<?php

namespace App\Models\Concerns;

use App\Models\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Запоминает, какой пользователь панели создал запись (колонка created_by). */
trait TracksCreator
{
    public static function bootTracksCreator(): void
    {
        static::creating(function ($model) {
            if ($model->created_by === null && auth()->check()) {
                $model->created_by = auth()->id();
            }
        });
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
