<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Land extends Model
{
    protected $fillable = [
        'name', 'location', 'area', 'purchase_date', 'purchase_price', 'status', 'notes',
    ];

    protected $casts = [
        'area' => 'decimal:2',
        'purchase_price' => 'decimal:2',
        'purchase_date' => 'date',
    ];

    public function buildings(): HasMany
    {
        return $this->hasMany(Building::class);
    }

    public function purchasePayments(): HasMany
    {
        return $this->hasMany(LandPurchasePayment::class);
    }

    public function expenses(): HasMany
    {
        return $this->hasMany(Expense::class);
    }

    public function refreshStatus(): void
    {
        $status = $this->buildings()->exists() ? 'linked' : 'vacant';
        if ($this->status !== $status) {
            $this->update(['status' => $status]);
        }
    }
}
