<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Payment extends Model
{
    protected $guarded = ['id'];

    protected $hidden = ['request_key', 'request_hash'];

    protected $casts = ['amount' => 'decimal:2', 'paid_on' => 'date', 'reversed_at' => 'datetime', 'receipt_snapshot' => 'array', 'contract_id' => 'integer', 'corrects_payment_id' => 'integer'];

    public function contract()
    {
        return $this->belongsTo(Contract::class);
    }

    public function events()
    {
        return $this->hasMany(PaymentEvent::class)->orderBy('id');
    }

    public function replacement()
    {
        return $this->hasOne(Payment::class, 'corrects_payment_id');
    }

    public function getReceiptNumberAttribute(): string
    {
        return 'R-'.str_pad((string) $this->id, 8, '0', STR_PAD_LEFT);
    }

    public function allocations()
    {
        return $this->hasMany(PaymentAllocation::class);
    }
}
