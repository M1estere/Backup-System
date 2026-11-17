<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BackupJob extends Model
{
    protected $guarded = [];

    protected $hidden = ['db_config'];

    protected static function booted(): void
    {
        // Имя папки в хранилищах: берётся из названия при создании и дальше не меняется.
        static::creating(function (BackupJob $job) {
            if (blank($job->slug)) {
                $base = \Illuminate\Support\Str::slug($job->name, '_') ?: 'job';
                $slug = $base;
                for ($i = 2; static::where('slug', $slug)->exists(); $i++) {
                    $slug = $base.'_'.$i;
                }
                $job->slug = $slug;
            }
        });
    }

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'paths' => 'array',
            'excludes' => 'array',
            'db_config' => 'encrypted:array',
        ];
    }

    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    public function storages(): BelongsToMany
    {
        return $this->belongsToMany(Storage::class, 'backup_job_storage')
            ->withPivot(['keep_daily', 'keep_weekly', 'keep_monthly']);
    }

    public function storageLinks(): HasMany
    {
        return $this->hasMany(BackupJobStorage::class);
    }

    public function runs(): HasMany
    {
        return $this->hasMany(Run::class);
    }

    public function lastSuccessfulRun(): ?Run
    {
        return $this->runs()->whereIn('status', ['success', 'partial'])->latest('finished_at')->first();
    }
}
