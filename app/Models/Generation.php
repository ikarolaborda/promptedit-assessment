<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Generation extends Model
{
    use HasUuids;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'reference_images' => 'array', 'rates' => 'array', 'usage' => 'array',
            'submission_uncertain' => 'boolean', 'needs_attention' => 'boolean',
            'completed_at' => 'immutable_datetime', 'image_url_expires_at' => 'immutable_datetime',
            'next_attempt_at' => 'immutable_datetime', 'last_synced_at' => 'immutable_datetime',
        ];
    }

    public function charge(): HasOne
    {
        return $this->hasOne(Charge::class);
    }

    public function terminal(): bool
    {
        return in_array($this->status, ['succeeded', 'failed'], true);
    }
}
