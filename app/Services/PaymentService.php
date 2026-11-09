<?php

namespace App\Services;

use App\Models\Contract;
use App\Models\Payment;
use App\Models\PaymentEvent;
use App\Models\Unit;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class PaymentService
{
    private function fail(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }

    private function lockContract(int $id): Contract
    {
        $original = Contract::findOrFail($id);
        Unit::lockForUpdate()->findOrFail($original->unit_id);

        return Contract::lockForUpdate()->findOrFail($id);
    }

    public function context(Contract $contract, ?Payment $source = null): array
    {
        if (in_array($contract->status, ['draft', 'cancelled'])) {
            $this->fail('contract_id', 'المسودة أو العقد الملغى لا يستقبلان مدفوعات.');
        }
        if ($source && ($source->contract_id !== $contract->id || $source->status !== 'posted')) {
            $this->fail('payment', 'التصحيح متاح لدفعة سارية من نفس العقد فقط.');
        }
        $version = (int) $contract->schedules()->max('version');
        if (! $version) {
            $this->fail('contract_id', 'لا يوجد جدول مستحقات لهذا العقد. أنشئ الجدول قبل تسجيل دفعة.');
        }
        $dues = $contract->dues()->where('is_void', false)->with('allocations.payment')->orderBy('due_date')->orderBy('id')->get();
        $open = [];
        $totals = ['principal' => 0, 'deposit' => 0];
        foreach ($dues as $due) {
            $paid = $due->allocations->sum(fn ($a) => $a->payment->status === 'posted' && (int) $a->payment_id !== $source?->id ? Money::cents($a->amount) : 0);
            $remaining = max(0, Money::cents($due->amount) - $paid);
            $bucket = $due->kind === 'security_deposit' ? 'deposit' : 'principal';
            $totals[$bucket] += $remaining;
            if ($remaining > 0) {
                $open[] = ['id' => $due->id, 'label' => $due->label, 'kind' => $due->kind, 'due_date' => $due->due_date->toDateString(), 'amount' => $due->amount, 'paid' => Money::decimal($paid), 'remaining' => Money::decimal($remaining)];
            }
        }
        $contract->loadMissing(['unit.building', 'customer']);

        return ['contract' => ['id' => $contract->id, 'number' => $contract->number, 'customer' => $contract->customer->only('id', 'name', 'phone'), 'unit' => ['id' => $contract->unit_id, 'number' => $contract->unit->number, 'building_name' => $contract->unit->building->name]],
            'schedule_version' => $version, 'dues' => $open, 'remaining' => array_map(fn ($v) => Money::decimal($v), $totals),
            'correction' => $source ? ['id' => $source->id, 'receipt_number' => $source->receipt_number, 'amount' => $source->amount, 'paid_on' => $source->paid_on->toDateString(), 'method' => $source->method, 'reference' => $source->reference, 'notes' => $source->notes, 'allocations' => $source->allocations()->get(['due_id', 'amount'])] : null,
        ];
    }

    private function plan(Contract $contract, array $input, ?Payment $source = null): array
    {
        $context = $this->context($contract, $source);
        if ($context['schedule_version'] !== (int) $input['expected_schedule_version']) {
            $this->fail('schedule', 'الجدول تغير. أعد تحميل الأرصدة ومراجعة الدفعة.');
        }
        if (Money::cents($context['remaining']['principal']) !== Money::cents($input['expected_principal_remaining']) || Money::cents($context['remaining']['deposit']) !== Money::cents($input['expected_deposit_remaining'])) {
            $this->fail('balance', 'رصيد العقد تغير منذ المراجعة. أعد تحميل الأرصدة قبل الحفظ.');
        }
        $available = collect($context['dues'])->keyBy('id');
        $sum = 0;
        $principal = 0;
        $deposit = 0;
        $lines = [];
        $ids = array_column($input['allocations'], 'due_id');
        $locked = $contract->dues()->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        foreach ($input['allocations'] as $i => $a) {
            $due = $locked->get($a['due_id']);
            $row = $available->get($a['due_id']);
            if (! $due || $due->is_void || ! $row) {
                $this->fail('allocations.'.$i.'.due_id', 'اختر مستحقًا غير ملغى وله رصيد من نفس العقد.');
            }
            $amount = Money::cents($a['amount']);
            $remaining = Money::cents($row['remaining']);
            if ($remaining !== Money::cents($a['expected_remaining'])) {
                $this->fail('balance', 'رصيد أحد المستحقات تغير. أعد تحميل الأرصدة.');
            }
            if ($amount > $remaining) {
                $this->fail('allocations.'.$i.'.amount', 'المبلغ المخصص لا يزيد عن المتبقي: '.$row['remaining'].' جنيه.');
            }
            $sum += $amount;
            if ($due->kind === 'security_deposit') {
                $deposit += $amount;
            } else {
                $principal += $amount;
            }
            $lines[] = ['due_id' => $due->id, 'label' => $due->label, 'kind' => $due->kind, 'due_date' => $due->due_date->toDateString(), 'amount' => Money::decimal($amount), 'remaining_before' => $row['remaining'], 'remaining_after' => Money::decimal($remaining - $amount)];
        }
        if ($sum !== Money::cents($input['amount'])) {
            $this->fail('allocations', 'مجموع المبالغ المخصصة يجب أن يساوي مبلغ الدفعة بالضبط.');
        }

        return ['contract' => $context['contract'], 'amount' => Money::decimal($sum), 'principal_amount' => Money::decimal($principal), 'deposit_amount' => Money::decimal($deposit),
            'paid_on' => $input['paid_on'], 'method' => $input['method'], 'reference' => $input['reference'] ?? null, 'notes' => $input['notes'] ?? null,
            'allocations' => $lines, 'contract_remaining_before' => $context['remaining'],
            'contract_remaining_after' => ['principal' => Money::decimal(Money::cents($context['remaining']['principal']) - $principal), 'deposit' => Money::decimal(Money::cents($context['remaining']['deposit']) - $deposit)],
        ];
    }

    public function preview(array $input): array
    {
        return DB::transaction(function () use ($input) {
            $c = $this->lockContract($input['contract_id']);
            $source = ! empty($input['corrects_payment_id']) ? Payment::lockForUpdate()->findOrFail($input['corrects_payment_id']) : null;

            return $this->plan($c, $input, $source);
        }, 3);
    }

    public function record(array $input, int $userId, ?Payment $original = null): Payment
    {
        $hash = hash('sha256', json_encode(['operation' => $original ? 'correct' : 'record', 'source_id' => $original?->id, 'data' => $input]));

        return DB::transaction(function () use ($input, $userId, $original, $hash) {
            $c = $this->lockContract($input['contract_id']);
            if ($existing = Payment::where('request_key', $input['request_key'])->first()) {
                if ($existing->request_hash !== $hash) {
                    throw new ConflictHttpException;
                }

                return $existing;
            }
            $source = $original ? Payment::lockForUpdate()->findOrFail($original->id) : null;
            $plan = $this->plan($c, $input, $source);
            $payment = Payment::create(['contract_id' => $c->id, 'user_id' => $userId, 'amount' => $plan['amount'], 'paid_on' => $input['paid_on'], 'method' => $input['method'],
                'reference' => $input['reference'] ?? null, 'notes' => $input['notes'] ?? null, 'status' => 'posted', 'request_key' => $input['request_key'], 'request_hash' => $hash, 'corrects_payment_id' => $source?->id]);
            foreach ($plan['allocations'] as $line) {
                $payment->allocations()->create(['due_id' => $line['due_id'], 'amount' => $line['amount']]);
            }
            $payment->receipt_snapshot = array_merge($plan, ['receipt_number' => $payment->receipt_number, 'issued_at' => now()->toIso8601String(), 'corrects_receipt_number' => $source?->receipt_number]);
            $payment->save();
            PaymentEvent::create(['payment_id' => $payment->id, 'user_id' => $userId, 'action' => 'created', 'reason' => $source ? 'بديل للإيصال '.$source->receipt_number : null]);
            if ($source) {
                $this->reverseRow($source, $userId, $input['reason']);
                PaymentEvent::create(['payment_id' => $source->id, 'user_id' => $userId, 'action' => 'corrected', 'reason' => $input['reason'], 'replacement_payment_id' => $payment->id, 'request_key' => $input['request_key'], 'request_hash' => $hash]);
            }

            return $payment;
        }, 3);
    }

    private function reverseRow(Payment $payment, int $userId, string $reason): void
    {
        $payment->update(['status' => 'reversed', 'reversed_at' => now(), 'reversed_by' => $userId, 'reversal_reason' => $reason]);
    }

    public function reverse(Payment $original, array $input, int $userId): Payment
    {
        $hash = hash('sha256', json_encode(['payment_id' => $original->id, 'data' => $input]));

        return DB::transaction(function () use ($original, $input, $userId, $hash) {
            $this->lockContract($original->contract_id);
            $payment = Payment::lockForUpdate()->findOrFail($original->id);
            if ($event = PaymentEvent::where('request_key', $input['request_key'])->first()) {
                if ($event->payment_id !== $payment->id || $event->action !== 'reversed' || $event->request_hash !== $hash) {
                    throw new ConflictHttpException;
                }

                return $payment;
            }
            if ($payment->status !== 'posted') {
                $this->fail('payment', 'هذه الدفعة معكوسة بالفعل. لا يمكن عكسها مرة ثانية.');
            }
            $this->reverseRow($payment, $userId, $input['reason']);
            PaymentEvent::create(['payment_id' => $payment->id, 'user_id' => $userId, 'action' => 'reversed', 'reason' => $input['reason'], 'request_key' => $input['request_key'], 'request_hash' => $hash]);

            return $payment;
        }, 3);
    }
}
