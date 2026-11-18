<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Project extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['enabled' => 'boolean', 'sync_db' => 'boolean'];
    }

    public function parentServer(): BelongsTo
    {
        return $this->belongsTo(Server::class, 'parent_server_id');
    }

    public function mirrors(): HasMany
    {
        return $this->hasMany(ProjectMirror::class);
    }

    public function syncs(): HasMany
    {
        return $this->hasMany(ProjectSync::class);
    }
}
