<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Unit extends Model
{
    protected $guarded = ['id'];

    protected $casts = ['area' => 'decimal:2', 'asking_sale_price' => 'decimal:2', 'asking_rent_price' => 'decimal:2', 'floor' => 'integer', 'building_id' => 'integer'];

    public function contracts()
    {
        return $this->hasMany(Contract::class);
    }

    public static function availabilitySql(): string
    {
        $today = today()->toDateString();

        return "(CASE WHEN EXISTS (SELECT 1 FROM contracts managed WHERE managed.unit_id = units.id AND managed.activated_at IS NOT NULL) THEN
            CASE WHEN EXISTS (SELECT 1 FROM contracts sales WHERE sales.unit_id = units.id AND sales.type = 'sale' AND sales.status IN ('active','completed') AND date(sales.start_date) <= '$today') THEN 'sold'
                 WHEN EXISTS (SELECT 1 FROM contracts rentals WHERE rentals.unit_id = units.id AND rentals.type = 'rental' AND rentals.status IN ('active','ended') AND date(rentals.start_date) <= '$today' AND date(rentals.effective_end_date) >= '$today') THEN 'rented'
                 ELSE 'available' END
            ELSE units.status END)";
    }

    public function scopeWithAvailability($query)
    {
        return $query->select('units.*')->selectRaw(self::availabilitySql().' as effective_status')
            ->withExists(['contracts as has_contract_history' => fn ($q) => $q->whereNotNull('activated_at')]);
    }

    public function availability(): string
    {
        return $this->attributes['effective_status'] ?? static::whereKey($this->id)->selectRaw(self::availabilitySql().' as availability')->first()?->getAttribute('availability') ?? $this->status;
    }

    public function identityLocked(): bool
    {
        return $this->status !== 'available' || ($this->attributes['has_contract_history'] ?? $this->contracts()->whereNotNull('activated_at')->exists());
    }

    public static function normalizeNumber(string $number): string
    {
        return trim(strtr($number, array_combine(
            preg_split('//u', '٠١٢٣٤٥٦٧٨٩۰۱۲۳۴۵۶۷۸۹', -1, PREG_SPLIT_NO_EMPTY),
            str_split('01234567890123456789')
        )));
    }

    public function setNumberAttribute(string $number): void
    {
        $this->attributes['number'] = self::normalizeNumber($number);
    }

    public function building(): BelongsTo
    {
        return $this->belongsTo(Building::class);
    }
}
