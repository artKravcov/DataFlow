<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
#[Fillable(['name'])]
class Organization extends Model
{
    use HasFactory;

    public function memberships(): HasMany
    {
        return $this->hasMany(Membership::class);
    }

    public function projects(): HasMany
    {
        return $this->hasMany(Project::class);
    }
}
