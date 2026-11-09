<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Contract extends Model
{
    protected $guarded = ['id'];

    protected $hidden = ['request_key', 'request_hash'];

    protected $casts = ['amount' => 'decimal:2', 'down_payment' => 'decimal:2', 'security_deposit' => 'decimal:2', 'start_date' => 'date', 'end_date' => 'date', 'effective_end_date' => 'date', 'first_due_date' => 'date', 'activated_at' => 'datetime'];

    public function dues()
    {
        return $this->hasMany(Due::class);
    }

    public function schedules()
    {
        return $this->hasMany(Schedule::class);
    }

    public function unit()
    {
        return $this->belongsTo(Unit::class)->withAvailability();
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function events()
    {
        return $this->hasMany(ContractEvent::class)->orderBy('id');
    }

    public function getNumberAttribute(): string
    {
        return 'C-'.str_pad((string) $this->id, 6, '0', STR_PAD_LEFT);
    }

    public function getEffectiveStatusAttribute(): string
    {
        return $this->type === 'rental' && $this->status === 'active' && $this->effective_end_date->lt(today()) ? 'ended' : $this->status;
    }
}
