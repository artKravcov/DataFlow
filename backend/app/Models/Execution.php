<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Execution extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['snapshot' => 'array', 'revision' => 'integer'];
    }
}
