<?php

namespace App\Models;

use App\Support\Money;
use Illuminate\Database\Eloquent\Model;

class Due extends Model
{
    protected $guarded = ['id'];

    protected $casts = ['amount' => 'decimal:2', 'due_date' => 'date', 'period_start' => 'date', 'period_end' => 'date', 'is_void' => 'boolean', 'voided_at' => 'datetime'];

    public function contract()
    {
        return $this->belongsTo(Contract::class);
    }

    public function allocations()
    {
        return $this->hasMany(PaymentAllocation::class);
    }

    public function paidCents(): int
    {
        $this->loadMissing('allocations.payment');

        return $this->allocations->sum(fn ($a) => $a->payment->status === 'posted' ? Money::cents($a->amount) : 0);
    }

    public static function paidSql(): string
    {
        return "COALESCE((SELECT SUM(pa.amount) FROM payment_allocations pa JOIN payments p ON p.id=pa.payment_id WHERE pa.due_id=dues.id AND p.status='posted'),0)";
    }

    public static function remainingSql(): string
    {
        return '(dues.amount - '.self::paidSql().')';
    }
}
