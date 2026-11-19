<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProjectMirror extends Model
{
    protected $guarded = [];

    protected $hidden = ['basic_auth_password', 'db_config', 'db_password'];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean', 'noindex' => 'boolean', 'basic_auth' => 'boolean',
            'basic_auth_password' => 'encrypted', 'db_config' => 'encrypted:array', 'db_password' => 'encrypted',
            'last_synced_at' => 'datetime',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }
}
