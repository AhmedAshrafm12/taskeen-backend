<?php

namespace App\Support;

class Money
{
    public static function cents($value): int
    {
        [$whole,$fraction] = array_pad(explode('.', (string) ($value ?? '0'), 2), 2, '');

        return ((int) $whole) * 100 + (int) str_pad($fraction, 2, '0');
    }

    public static function decimal(int $cents): string
    {
        return intdiv($cents, 100).'.'.str_pad((string) ($cents % 100), 2, '0', STR_PAD_LEFT);
    }
}
