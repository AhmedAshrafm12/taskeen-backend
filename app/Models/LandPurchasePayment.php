<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LandPurchasePayment extends Model
{
    protected $fillable = [
        'land_id', 'amount', 'paid_on', 'notes', 'user_id',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'paid_on' => 'date',
        'land_id' => 'integer',
        'user_id' => 'integer',
    ];

    public function land(): BelongsTo
    {
        return $this->belongsTo(Land::class);
    }
}
