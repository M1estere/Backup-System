<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Run extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function backupJob(): BelongsTo
    {
        return $this->belongsTo(BackupJob::class);
    }

    public function targets(): HasMany
    {
        return $this->hasMany(RunTarget::class);
    }

    public function logs(): HasMany
    {
        return $this->hasMany(RunLog::class);
    }

    public function log(string $message, string $level = 'info', ?string $step = null): void
    {
        $this->logs()->create(['message' => $message, 'level' => $level, 'step' => $step]);
    }
}
