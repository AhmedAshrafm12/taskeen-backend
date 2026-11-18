<?php

namespace App\Services;

use App\Models\Expense;
use App\Models\ExpensePayment;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ExpenseService
{
    public function __construct(private CashLedgerService $cash) {}

    public function create(array $input, int $userId): Expense
    {
        return DB::transaction(function () use ($input, $userId) {
            $this->cash->lockForUpdate();
            $cents = Money::cents($input['amount']);
            if ($cents <= 0) {
                throw ValidationException::withMessages(['amount' => 'قيمة المصروف يجب أن تكون أكبر من صفر.']);
            }
            $payNow = Money::cents($input['pay_now'] ?? 0);
            if ($payNow < 0 || $payNow > $cents) {
                throw ValidationException::withMessages(['pay_now' => 'المبلغ المدفوع الآن غير صالح.']);
            }
            if ($payNow > 0) {
                $this->cash->assertCanDebit($payNow);
            }
            $status = $payNow === 0 ? 'unpaid' : ($payNow === $cents ? 'paid' : 'partial');
            $expense = Expense::create([
                'category' => $input['category'],
                'description' => trim($input['description']),
                'amount' => Money::decimal($cents),
                'occurred_on' => $input['occurred_on'],
                'status' => $status,
                'land_id' => $input['land_id'] ?? null,
                'building_id' => $input['building_id'] ?? null,
                'notes' => $input['notes'] ?? null,
                'user_id' => $userId,
            ]);
            if ($payNow > 0) {
                $this->addPaymentRow($expense, [
                    'amount' => Money::decimal($payNow),
                    'paid_on' => $input['paid_on'] ?? $input['occurred_on'],
                    'notes' => $input['payment_notes'] ?? null,
                ], $userId);
            }

            return $expense->fresh('payments');
        }, 3);
    }

    public function recordPayment(Expense $expense, array $input, int $userId): ExpensePayment
    {
        return DB::transaction(function () use ($expense, $input, $userId) {
            $this->cash->lockForUpdate();
            $expense = Expense::lockForUpdate()->findOrFail($expense->id);
            $cents = Money::cents($input['amount']);
            $paid = Money::cents($expense->payments()->sum('amount'));
            $total = Money::cents($expense->amount);
            $remaining = max(0, $total - $paid);
            if ($cents <= 0 || $cents > $remaining) {
                throw ValidationException::withMessages([
                    'amount' => 'مبلغ الدفعة غير صالح. المتبقي '.Money::decimal($remaining).' ج.م.',
                ]);
            }
            $this->cash->assertCanDebit($cents);

            return $this->addPaymentRow($expense, $input, $userId);
        }, 3);
    }

    private function addPaymentRow(Expense $expense, array $input, int $userId): ExpensePayment
    {
        $cents = Money::cents($input['amount']);
        $payment = ExpensePayment::create([
            'expense_id' => $expense->id,
            'amount' => Money::decimal($cents),
            'paid_on' => $input['paid_on'],
            'notes' => $input['notes'] ?? null,
            'user_id' => $userId,
        ]);
        $this->cash->post('out', $cents, $input['paid_on'], 'expense_payment', $payment->id, 'دفعة مصروف: '.$expense->description, $payment->notes);
        $paid = Money::cents($expense->payments()->sum('amount'));
        $total = Money::cents($expense->amount);
        $expense->update([
            'status' => $paid <= 0 ? 'unpaid' : ($paid >= $total ? 'paid' : 'partial'),
        ]);

        return $payment;
    }
}
