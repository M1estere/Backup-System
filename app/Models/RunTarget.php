<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RunTarget extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['objects' => 'array'];
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(Run::class);
    }

    public function storage(): BelongsTo
    {
        return $this->belongsTo(Storage::class);
    }
}
