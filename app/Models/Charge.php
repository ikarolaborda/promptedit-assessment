<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Charge extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['completed_at' => 'immutable_datetime'];
    }
}
