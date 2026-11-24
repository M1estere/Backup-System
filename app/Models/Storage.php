<?php

namespace App\Models;

use App\Models\Concerns\TracksCreator;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Storage extends Model
{
    public const TYPE_S3 = 's3';
    public const TYPE_SFTP = 'sftp';
    public const TYPE_LOCAL = 'local';

    public const TYPES = [
        self::TYPE_S3 => 'Amazon S3 (или совместимое)',
        self::TYPE_SFTP => 'Windows / SFTP',
        self::TYPE_LOCAL => 'Локальная папка',
    ];

    use TracksCreator;

    protected $guarded = [];

    protected $hidden = ['config'];

    protected static function booted(): void
    {
        // Нельзя удалить хранилище, которое используется в заданиях.
        static::deleting(fn (Storage $s) => $s->backupJobs()->doesntExist());
    }

    protected function casts(): array
    {
        return [
            'config' => 'encrypted:array',
            'last_checked_at' => 'datetime',
        ];
    }

    public function runTargets(): HasMany
    {
        return $this->hasMany(RunTarget::class);
    }

    public function backupJobs(): BelongsToMany
    {
        return $this->belongsToMany(BackupJob::class, 'backup_job_storage')
            ->withPivot(['keep_daily', 'keep_weekly', 'keep_monthly']);
    }
}
