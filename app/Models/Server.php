<?php

namespace App\Models;

use App\Models\Concerns\TracksCreator;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Server extends Model
{
    use TracksCreator;

    protected $guarded = [];

    protected $hidden = ['password'];

    protected static function booted(): void
    {
        // Нельзя удалить сервер, у которого есть задания (каскад молча стёр бы задания и историю запусков).
        static::deleting(fn (Server $s) => $s->backupJobs()->doesntExist());
    }

    protected function casts(): array
    {
        return [
            'password' => 'encrypted',
            'capabilities' => 'array',
            'last_checked_at' => 'datetime',
        ];
    }

    public function backupJobs(): HasMany
    {
        return $this->hasMany(BackupJob::class);
    }
}
