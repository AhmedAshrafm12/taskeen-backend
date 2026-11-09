<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Building extends Model
{
    protected $fillable = ['name', 'address', 'floors', 'notes', 'is_active'];

    protected $casts = ['is_active' => 'boolean', 'floors' => 'integer'];

    public function units(): HasMany
    {
        return $this->hasMany(Unit::class);
    }
}
