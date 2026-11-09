<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Customer extends Model
{
    protected $fillable = ['name', 'phone', 'email', 'address', 'notes'];

    public function contracts()
    {
        return $this->hasMany(Contract::class);
    }

    public function setPhoneAttribute(string $phone): void
    {
        $this->attributes['phone'] = self::normalizePhone($phone);
    }

    public static function normalizePhone(string $phone): string
    {
        $phone = trim(strtr($phone, array_combine(
            preg_split('//u', '٠١٢٣٤٥٦٧٨٩۰۱۲۳۴۵۶۷۸۹', -1, PREG_SPLIT_NO_EMPTY),
            str_split('01234567890123456789')
        )));
        // Preserve invalid characters for validation rather than silently discarding them.
        if (! preg_match('/^\+?[0-9\s().-]+$/u', $phone)) {
            return $phone;
        }
        $phone = preg_replace('/[\s().-]+/u', '', $phone);
        if (str_starts_with($phone, '00')) {
            $phone = '+'.substr($phone, 2);
        } elseif (str_starts_with($phone, '0')) {
            $phone = '+20'.substr($phone, 1);
        } elseif (str_starts_with($phone, '20')) {
            $phone = '+'.$phone;
        } elseif (preg_match('/^1[0125][0-9]{8}$/', $phone)) {
            $phone = '+20'.$phone;
        }
        if (str_starts_with($phone, '+200')) {
            $phone = '+20'.ltrim(substr($phone, 3), '0');
        }

        return $phone;
    }
}
