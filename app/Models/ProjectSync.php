<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProjectSync extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['started_at' => 'datetime', 'finished_at' => 'datetime'];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function addLog(string $message, string $level = 'info'): void
    {
        $line = '['.now()->format('H:i:s').'] '.strtoupper($level).' '.$message."\n";
        $this->log = ($this->log ?? '').$line;
        $this->save();
    }
}
