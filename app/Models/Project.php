<?php

namespace App\Models;

use App\Models\Concerns\TracksCreator;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Project extends Model
{
    use TracksCreator;

    protected $guarded = [];

    protected $hidden = ['db_password'];

    protected function casts(): array
    {
        return ['enabled' => 'boolean', 'sync_db' => 'boolean', 'db_password' => 'encrypted'];
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
