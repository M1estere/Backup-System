<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class NotificationSetting extends Model
{
    protected $guarded = [];

    protected $hidden = ['smtp_password'];

    protected function casts(): array
    {
        return [
            'on_failure' => 'boolean', 'on_success' => 'boolean', 'digest_enabled' => 'boolean',
            'smtp_password' => 'encrypted', 'last_digest_on' => 'date',
        ];
    }

    public static function current(): self
    {
        return static::firstOrCreate(['id' => 1]);
    }

    /** @return list<string> */
    public function recipients(): array
    {
        $list = preg_split('/[\s,;]+/', (string) $this->emails, -1, PREG_SPLIT_NO_EMPTY);

        return array_values(array_unique(array_filter($list, fn ($e) => filter_var($e, FILTER_VALIDATE_EMAIL))));
    }

    public function isConfigured(): bool
    {
        return $this->smtp_host && $this->from_address && $this->recipients();
    }
}
