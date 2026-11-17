<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Связь «задание — хранилище» с индивидуальной ретенцией. */
class BackupJobStorage extends Model
{
    protected $table = 'backup_job_storage';

    public $timestamps = false;

    protected $guarded = [];

    public function backupJob(): BelongsTo
    {
        return $this->belongsTo(BackupJob::class);
    }

    public function storage(): BelongsTo
    {
        return $this->belongsTo(Storage::class);
    }
}
