<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CashTransaction extends Model
{
    protected $fillable = [
        'direction', 'signed_amount', 'occurred_on', 'source_type', 'source_id', 'label', 'notes',
    ];

    protected $casts = [
        'signed_amount' => 'decimal:2',
        'occurred_on' => 'date',
        'source_id' => 'integer',
    ];
}
