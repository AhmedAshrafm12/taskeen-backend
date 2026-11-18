<?php

namespace App\Services;

use App\Models\CashTransaction;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CashLedgerService
{
    public function balanceCents(): int
    {
        $sum = CashTransaction::query()->sum('signed_amount');

        return Money::cents($sum ?? 0);
    }

    public function balance(): string
    {
        return Money::decimal($this->balanceCents());
    }

    public function assertCanDebit(int $cents): void
    {
        if ($cents <= 0) {
            throw ValidationException::withMessages(['amount' => 'المبلغ يجب أن يكون أكبر من صفر.']);
        }
        if ($this->balanceCents() < $cents) {
            throw ValidationException::withMessages([
                'amount' => 'الرصيد النقدي غير كافٍ. الرصيد الحالي '.Money::decimal($this->balanceCents()).' ج.م.',
            ]);
        }
    }

    public function post(string $direction, int $cents, string $occurredOn, string $sourceType, int $sourceId, string $label, ?string $notes = null): CashTransaction
    {
        if ($cents <= 0) {
            throw ValidationException::withMessages(['amount' => 'المبلغ يجب أن يكون أكبر من صفر.']);
        }
        if (! in_array($direction, ['in', 'out'], true)) {
            throw ValidationException::withMessages(['direction' => 'اتجاه الحركة غير صالح.']);
        }

        if ($existing = CashTransaction::where('source_type', $sourceType)->where('source_id', $sourceId)->first()) {
            return $existing;
        }

        if ($direction === 'out') {
            $this->assertCanDebit($cents);
        }

        $signed = $direction === 'in' ? $cents : -$cents;

        return CashTransaction::create([
            'direction' => $direction,
            'signed_amount' => Money::decimal($signed),
            'occurred_on' => $occurredOn,
            'source_type' => $sourceType,
            'source_id' => $sourceId,
            'label' => $label,
            'notes' => $notes,
        ]);
    }

    public function periodTotals(?string $from = null, ?string $to = null): array
    {
        $query = CashTransaction::query();
        if ($from) {
            $query->whereDate('occurred_on', '>=', $from);
        }
        if ($to) {
            $query->whereDate('occurred_on', '<=', $to);
        }
        $in = 0;
        $out = 0;
        foreach ($query->get(['signed_amount', 'direction']) as $row) {
            $cents = Money::cents($row->signed_amount);
            if ($cents >= 0) {
                $in += $cents;
            } else {
                $out += abs($cents);
            }
        }

        return [
            'inflow' => Money::decimal($in),
            'outflow' => Money::decimal($out),
        ];
    }

    public function lockForUpdate(): void
    {
        // Serialize cash mutations across concurrent requests.
        DB::table('cash_transactions')->lockForUpdate()->limit(1)->get();
    }
}
