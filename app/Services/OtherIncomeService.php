<?php

namespace App\Services;

use App\Models\OtherIncome;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class OtherIncomeService
{
    public function __construct(private CashLedgerService $cash) {}

    public function create(array $input, int $userId): OtherIncome
    {
        return DB::transaction(function () use ($input, $userId) {
            $this->cash->lockForUpdate();
            $cents = Money::cents($input['amount']);
            if ($cents <= 0) {
                throw ValidationException::withMessages(['amount' => 'مبلغ الإيراد يجب أن يكون أكبر من صفر.']);
            }
            $row = OtherIncome::create([
                'category' => $input['category'] ?? 'other',
                'description' => trim($input['description']),
                'amount' => Money::decimal($cents),
                'occurred_on' => $input['occurred_on'],
                'land_id' => $input['land_id'] ?? null,
                'building_id' => $input['building_id'] ?? null,
                'notes' => $input['notes'] ?? null,
                'user_id' => $userId,
            ]);
            $this->cash->post('in', $cents, $input['occurred_on'], 'other_income', $row->id, 'إيراد آخر: '.$row->description, $row->notes);

            return $row;
        }, 3);
    }
}
