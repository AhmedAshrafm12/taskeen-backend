<?php

namespace App\Services;

use App\Models\CapitalTransaction;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CapitalService
{
    public function __construct(private CashLedgerService $cash) {}

    public function hasOpeningBalance(): bool
    {
        return CapitalTransaction::where('type', 'opening_balance')->exists();
    }

    public function opening(array $input, int $userId): CapitalTransaction
    {
        return DB::transaction(function () use ($input, $userId) {
            $this->cash->lockForUpdate();
            if ($this->hasOpeningBalance()) {
                throw ValidationException::withMessages(['amount' => 'تم تسجيل الرصيد الافتتاحي مسبقًا. استخدم إضافة رأس مال أو سحب.']);
            }
            $cents = Money::cents($input['amount']);
            if ($cents < 0) {
                throw ValidationException::withMessages(['amount' => 'الرصيد الافتتاحي لا يمكن أن يكون سالبًا.']);
            }
            $row = CapitalTransaction::create([
                'type' => 'opening_balance',
                'amount' => Money::decimal($cents),
                'occurred_on' => $input['occurred_on'],
                'reference' => $input['reference'] ?? null,
                'notes' => $input['notes'] ?? null,
                'user_id' => $userId,
            ]);
            if ($cents > 0) {
                $this->cash->post('in', $cents, $input['occurred_on'], 'capital_transaction', $row->id, 'رصيد افتتاحي', $row->notes);
            }

            return $row;
        }, 3);
    }

    public function add(array $input, int $userId): CapitalTransaction
    {
        return DB::transaction(function () use ($input, $userId) {
            $this->cash->lockForUpdate();
            if (! $this->hasOpeningBalance()) {
                throw ValidationException::withMessages(['amount' => 'سجّل الرصيد الافتتاحي أولًا.']);
            }
            $cents = Money::cents($input['amount']);
            if ($cents <= 0) {
                throw ValidationException::withMessages(['amount' => 'مبلغ الإضافة يجب أن يكون أكبر من صفر.']);
            }
            $row = CapitalTransaction::create([
                'type' => 'capital_addition',
                'amount' => Money::decimal($cents),
                'occurred_on' => $input['occurred_on'],
                'reference' => $input['reference'] ?? null,
                'notes' => $input['notes'] ?? null,
                'user_id' => $userId,
            ]);
            $this->cash->post('in', $cents, $input['occurred_on'], 'capital_transaction', $row->id, 'إضافة رأس مال', $row->notes);

            return $row;
        }, 3);
    }

    public function withdraw(array $input, int $userId): CapitalTransaction
    {
        return DB::transaction(function () use ($input, $userId) {
            $this->cash->lockForUpdate();
            if (! $this->hasOpeningBalance()) {
                throw ValidationException::withMessages(['amount' => 'سجّل الرصيد الافتتاحي أولًا.']);
            }
            $cents = Money::cents($input['amount']);
            if ($cents <= 0) {
                throw ValidationException::withMessages(['amount' => 'مبلغ السحب يجب أن يكون أكبر من صفر.']);
            }
            $this->cash->assertCanDebit($cents);
            $row = CapitalTransaction::create([
                'type' => 'owner_withdrawal',
                'amount' => Money::decimal($cents),
                'occurred_on' => $input['occurred_on'],
                'reference' => $input['reference'] ?? null,
                'notes' => $input['notes'] ?? null,
                'user_id' => $userId,
            ]);
            $this->cash->post('out', $cents, $input['occurred_on'], 'capital_transaction', $row->id, 'سحب للمالك', $row->notes);

            return $row;
        }, 3);
    }
}
