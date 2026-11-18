<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Building extends Model
{
    protected $fillable = ['land_id', 'name', 'address', 'floors', 'notes', 'is_active'];

    protected $casts = ['is_active' => 'boolean', 'floors' => 'integer', 'land_id' => 'integer'];

    public function land(): BelongsTo
    {
        return $this->belongsTo(Land::class);
    }

    public function units(): HasMany
    {
        return $this->hasMany(Unit::class);
    }
}
