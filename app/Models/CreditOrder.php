<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CreditOrder extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['livemode' => 'boolean', 'credited_at' => 'immutable_datetime'];
    }
}
