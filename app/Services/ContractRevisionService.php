<?php

namespace App\Services;

use App\Models\Contract;
use App\Models\ContractEvent;
use App\Models\ContractRevision;
use App\Models\Due;
use App\Models\PaymentAllocation;
use App\Models\Unit;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class ContractRevisionService
{
    public function __construct(private ScheduleService $schedule, private CashLedgerService $cash) {}

    private function fail(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }

    public function preview(Contract $contract, array $input): array
    {
        return DB::transaction(function () use ($contract, $input) {
            Unit::lockForUpdate()->findOrFail($contract->unit_id);
            $c = Contract::lockForUpdate()->with(['dues.allocations.payment', 'schedules'])->findOrFail($contract->id);
            $this->assertEligible($c);
            $this->assertConcurrency($c, $input);

            return $this->payload($this->buildPlan($c, $input));
        }, 3);
    }

    public function commit(Contract $contract, array $input, int $userId): ContractRevision
    {
        $hash = hash('sha256', json_encode($input));

        return DB::transaction(function () use ($contract, $input, $userId, $hash) {
            $this->cash->lockForUpdate();
            Unit::lockForUpdate()->findOrFail($contract->unit_id);
            $c = Contract::lockForUpdate()->with(['dues.allocations.payment', 'schedules'])->findOrFail($contract->id);
            if ($existing = ContractRevision::where('request_key', $input['request_key'])->first()) {
                if ($existing->contract_id !== $c->id || $existing->request_hash !== $hash) {
                    throw new ConflictHttpException;
                }

                return $existing;
            }
            $this->assertEligible($c);
            $this->assertConcurrency($c, $input);
            $plan = $this->buildPlan($c, $input);
            $this->applyPlan($c, $plan, $input, $userId, $hash);

            return ContractRevision::where('request_key', $input['request_key'])->firstOrFail();
        }, 3);
    }

    private function assertEligible(Contract $c): void
    {
        if (in_array($c->status, ['draft', 'cancelled'], true)) {
            $this->fail('contract', 'لا يمكن تعديل شروط مسودة أو عقد ملغى.');
        }
        if (! $c->schedules()->exists()) {
            $this->fail('contract', 'لا يوجد جدول مستحقات لهذا العقد.');
        }
    }

    private function assertConcurrency(Contract $c, array $input): void
    {
        $version = (int) $c->schedules()->max('version');
        if ((int) $input['expected_schedule_version'] !== $version) {
            $this->fail('expected_schedule_version', 'الجدول تغير بالفعل. حدّث الصفحة وراجع أحدث نسخة.');
        }
        $paid = $this->principalPaidCents($c);
        if (Money::cents($input['expected_principal_paid']) !== $paid) {
            $this->fail('expected_principal_paid', 'المحصّل تغير بعد فتح التعديل. حدّث الصفحة وأعد المعاينة.');
        }
    }

    private function principalPaidCents(Contract $c): int
    {
        $sum = 0;
        foreach ($c->dues as $due) {
            if ($due->is_void || $due->kind === 'security_deposit') {
                continue;
            }
            $sum += $due->paidCents();
        }

        return $sum;
    }

    private function proposedTerms(Contract $c, array $input): array
    {
        $amount = $input['amount'] ?? $c->amount;
        $start = $input['start_date'] ?? $c->start_date->toDateString();
        $down = $input['down_payment'] ?? $c->down_payment;
        $deposit = $input['security_deposit'] ?? $c->security_deposit;
        $frequency = array_key_exists('frequency', $input) ? $input['frequency'] : $c->frequency;
        $count = array_key_exists('installments_count', $input) ? $input['installments_count'] : $c->installments_count;
        $first = array_key_exists('first_due_date', $input) ? $input['first_due_date'] : $c->first_due_date?->toDateString();
        $end = array_key_exists('end_date', $input) ? $input['end_date'] : $c->end_date?->toDateString();

        if (Money::cents($amount) <= 0) {
            $this->fail('amount', 'قيمة العقد يجب أن تكون أكبر من صفر.');
        }
        if ($c->type === 'sale' && $c->sale_mode === 'installments') {
            if (Money::cents($down) >= Money::cents($amount)) {
                $this->fail('down_payment', 'المقدم يجب أن يكون أقل من قيمة البيع بالتقسيط.');
            }
            $count = (int) $count;
            if ($count < 1 || $count > 600) {
                $this->fail('installments_count', 'عدد الأقساط غير صالح.');
            }
            if (Money::cents($amount) - Money::cents($down) < $count) {
                $this->fail('installments_count', 'عدد الأقساط لا يسمح بقسط أقل من قرش.');
            }
            if (! $first || $first < $start) {
                $this->fail('first_due_date', 'تاريخ أول استحقاق يجب ألا يسبق بداية العقد.');
            }
            if (! in_array($frequency, ['monthly', 'quarterly', 'yearly'], true)) {
                $this->fail('frequency', 'دورية السداد غير صحيحة.');
            }
        }
        if ($c->type === 'rental') {
            if (! $end || $end < $start) {
                $this->fail('end_date', 'نهاية الإيجار يجب ألا تسبق البداية.');
            }
            if (! in_array($frequency, ['monthly', 'quarterly', 'yearly'], true)) {
                $this->fail('frequency', 'دورية السداد غير صحيحة.');
            }
        }

        return [
            'amount' => Money::decimal(Money::cents($amount)),
            'start_date' => $start,
            'end_date' => $c->type === 'rental' ? $end : null,
            'effective_end_date' => $c->type === 'rental' ? $end : $c->effective_end_date?->toDateString(),
            'down_payment' => $c->sale_mode === 'installments' ? Money::decimal(Money::cents($down)) : '0.00',
            'security_deposit' => $c->type === 'rental' ? Money::decimal(Money::cents($deposit)) : '0.00',
            'frequency' => $c->type === 'rental' || $c->sale_mode === 'installments' ? $frequency : null,
            'installments_count' => $c->sale_mode === 'installments' ? (int) $count : null,
            'first_due_date' => $c->sale_mode === 'installments' ? $first : null,
            'notes' => array_key_exists('notes', $input) ? $input['notes'] : $c->notes,
        ];
    }

    private function buildPlan(Contract $c, array $input): array
    {
        $dateScope = $input['date_scope'];
        $amountScope = $input['amount_scope'];
        $terms = $this->proposedTerms($c, $input);
        $clone = $c->replicate();
        $clone->fill($terms);
        $clone->id = $c->id;
        $targetPrincipal = $c->type === 'sale'
            ? Money::cents($terms['amount'])
            : array_sum(array_map(fn ($r) => $r['kind'] === 'security_deposit' ? 0 : Money::cents($r['amount']), $this->schedule->automatic($clone)));

        $rows = [];
        foreach ($c->dues->where('is_void', false)->sortBy([['due_date', 'asc'], ['id', 'asc']])->values() as $due) {
            $paid = $due->paidCents();
            $rows[] = [
                'id' => $due->id,
                'kind' => $due->kind,
                'label' => $due->label,
                'due_date' => $due->due_date->toDateString(),
                'amount' => Money::cents($due->amount),
                'paid' => $paid,
                'period_start' => $due->period_start?->toDateString(),
                'period_end' => $due->period_end?->toDateString(),
                'sequence' => $due->sequence,
                'schedule_id' => $due->schedule_id,
                'void' => false,
                'created' => false,
            ];
        }

        $this->applyDateScope($c, $terms, $dateScope, $rows);
        $this->syncRentalPeriods($clone, $dateScope, $rows);
        $this->syncInstallmentCount($c, $terms, $amountScope, $rows);
        $this->applyAmountScope($c, $terms, $amountScope, $targetPrincipal, $input['dues'] ?? [], $rows);
        $this->applyDeposit($terms, $rows);

        $surplus = 0;
        foreach ($rows as $row) {
            if ($row['void'] || $row['kind'] === 'security_deposit') {
                continue;
            }
            $surplus += max(0, $row['paid'] - $row['amount']);
        }
        foreach ($rows as $row) {
            if ($row['void'] || $row['kind'] !== 'security_deposit') {
                continue;
            }
            $surplus += max(0, $row['paid'] - $row['amount']);
        }

        $action = $input['surplus_action'] ?? null;
        if ($surplus > 0 && ! in_array($action, ['reallocate', 'credit', 'refund'], true)) {
            $this->fail('surplus_action', 'يوجد مبلغ محصّل أكبر من الالتزام الجديد. اختر تحويل الزيادة أو رصيدًا دائنًا أو رد مبلغ.');
        }
        if ($surplus === 0) {
            $action = null;
        }
        if ($action === 'refund' && empty($input['refund_confirmed'])) {
            $this->fail('refund_confirmed', 'أكد رد المبلغ النقدي قبل الحفظ. هذا يخصم من الخزنة.');
        }

        $allocationMoves = [];
        $credit = 0;
        $refund = 0;
        if ($action === 'reallocate') {
            $allocationMoves = $this->planReallocate($c, $rows);
        } elseif ($action === 'credit') {
            $credit = $surplus;
        } elseif ($action === 'refund') {
            $refund = $surplus;
            $this->planRefundAllocations($c, $rows, $allocationMoves);
        }

        $principalSum = 0;
        $depositSum = 0;
        $collected = 0;
        foreach ($rows as $row) {
            if ($row['void']) {
                continue;
            }
            if ($row['kind'] === 'security_deposit') {
                $depositSum += $row['amount'];
            } else {
                $principalSum += $row['amount'];
                $collected += $row['paid'];
            }
        }
        if ($principalSum !== $targetPrincipal) {
            $this->fail('amount', 'مجموع الاستحقاقات بعد التعديل ('.Money::decimal($principalSum).') لا يطابق قيمة العقد المستهدفة ('.Money::decimal($targetPrincipal).').');
        }
        if ($c->type === 'rental' && $depositSum !== Money::cents($terms['security_deposit'])) {
            $this->fail('security_deposit', 'استحقاق التأمين يجب أن يطابق قيمة التأمين الجديدة.');
        }

        $warnings = [];
        if ($dateScope !== 'contract_only') {
            $warnings[] = 'قد تتغير حالة التأخير التاريخية لأن تاريخ الاستحقاق مستقل عن تاريخ الدفع الفعلي.';
        }
        if ($refund > 0) {
            $warnings[] = 'رد المبلغ سيخصم من الرصيد النقدي. التحصيلات الأصلية تبقى في السجل.';
        }
        if ($credit > 0) {
            $warnings[] = 'الزيادة تُحفظ كرصيد دائن للعميل دون تغيير حركة الخزنة.';
        }

        return [
            'terms' => $terms,
            'rows' => $rows,
            'target_principal' => $targetPrincipal,
            'collected' => $collected,
            'remaining' => max(0, $targetPrincipal - $collected),
            'surplus' => $surplus,
            'surplus_action' => $action,
            'credit' => $credit,
            'refund' => $refund,
            'allocation_moves' => $allocationMoves,
            'warnings' => $warnings,
            'date_scope' => $dateScope,
            'amount_scope' => $amountScope,
            'before_terms' => [
                'amount' => $c->amount,
                'start_date' => $c->start_date->toDateString(),
                'end_date' => $c->end_date?->toDateString(),
                'down_payment' => $c->down_payment,
                'security_deposit' => $c->security_deposit,
                'frequency' => $c->frequency,
                'installments_count' => $c->installments_count,
                'first_due_date' => $c->first_due_date?->toDateString(),
                'credit_balance' => $c->credit_balance,
            ],
            'before_rows' => $c->dues->where('is_void', false)->sortBy([['due_date', 'asc'], ['id', 'asc']])->values()->map(function ($due) {
                $paid = $due->paidCents();

                return [
                    'id' => $due->id,
                    'kind' => $due->kind,
                    'label' => $due->label,
                    'due_date' => $due->due_date->toDateString(),
                    'amount' => Money::decimal(Money::cents($due->amount)),
                    'paid' => Money::decimal($paid),
                    'remaining' => Money::decimal(max(0, Money::cents($due->amount) - $paid)),
                ];
            })->all(),
        ];
    }

    private function applyDateScope(Contract $c, array $terms, string $scope, array &$rows): void
    {
        if ($scope === 'contract_only') {
            return;
        }
        $oldAnchor = $c->sale_mode === 'installments' && $c->first_due_date
            ? $c->first_due_date->toDateString()
            : $c->start_date->toDateString();
        $newAnchor = $c->sale_mode === 'installments' && $terms['first_due_date']
            ? $terms['first_due_date']
            : $terms['start_date'];
        $startDays = (int) CarbonImmutable::parse($c->start_date->toDateString())->diffInDays(CarbonImmutable::parse($terms['start_date']), false);
        $anchorDays = (int) CarbonImmutable::parse($oldAnchor)->diffInDays(CarbonImmutable::parse($newAnchor), false);
        foreach ($rows as &$row) {
            if ($row['void']) {
                continue;
            }
            $unpaid = $row['paid'] < $row['amount'];
            if ($scope === 'unpaid_only' && ! $unpaid) {
                continue;
            }
            $days = in_array($row['kind'], ['sale_installment', 'adjustment'], true) ? $anchorDays : $startDays;
            if ($days === 0) {
                continue;
            }
            $row['due_date'] = CarbonImmutable::parse($row['due_date'])->addDays($days)->toDateString();
            if ($row['period_start']) {
                $row['period_start'] = CarbonImmutable::parse($row['period_start'])->addDays($days)->toDateString();
            }
            if ($row['period_end']) {
                $row['period_end'] = CarbonImmutable::parse($row['period_end'])->addDays($days)->toDateString();
            }
        }
        unset($row);
    }

    private function syncRentalPeriods(Contract $clone, string $dateScope, array &$rows): void
    {
        if ($clone->type !== 'rental' || $dateScope === 'contract_only') {
            return;
        }
        $end = $clone->effective_end_date->toDateString();
        foreach ($rows as &$row) {
            if ($row['void'] || $row['kind'] !== 'rent') {
                continue;
            }
            if ($row['period_end'] && $row['period_end'] > $end) {
                $row['period_end'] = $end;
            }
            $start = $row['period_start'] ?? $row['due_date'];
            if ($start > $end && $row['paid'] === 0) {
                $row['void'] = true;
            } elseif ($start > $end) {
                $this->fail('end_date', 'لا يمكن إنهاء الفترة قبل استحقاق مدفوع. اختر تعديل كل الاستحقاقات أو أبقِ النهاية بعد آخر قسط محصّل.');
            }
        }
        unset($row);
        $existing = [];
        foreach ($rows as $row) {
            if (! $row['void'] && $row['kind'] === 'rent') {
                $existing[$row['period_start'] ?? $row['due_date']] = true;
            }
        }
        $scheduleId = $rows[0]['schedule_id'] ?? $clone->schedules()->latest('version')->value('id');
        $sequence = 0;
        foreach ($rows as $row) {
            $sequence = max($sequence, $row['sequence']);
        }
        foreach ($this->schedule->automatic($clone) as $auto) {
            if ($auto['kind'] !== 'rent') {
                continue;
            }
            $key = $auto['period_start'] ?? $auto['due_date'];
            if (isset($existing[$key])) {
                continue;
            }
            $sequence++;
            $rows[] = [
                'id' => null,
                'kind' => 'rent',
                'label' => $auto['label'],
                'due_date' => $auto['due_date'],
                'amount' => Money::cents($auto['amount']),
                'paid' => 0,
                'period_start' => $auto['period_start'],
                'period_end' => $auto['period_end'],
                'sequence' => $sequence,
                'schedule_id' => $scheduleId,
                'void' => false,
                'created' => true,
            ];
        }
    }

    private function syncInstallmentCount(Contract $c, array $terms, string $amountScope, array &$rows): void
    {
        if ($c->sale_mode !== 'installments') {
            return;
        }
        $wanted = (int) $terms['installments_count'];
        $installments = array_values(array_filter($rows, fn ($r) => ! $r['void'] && $r['kind'] === 'sale_installment'));
        $have = count($installments);
        $scheduleId = $rows[0]['schedule_id'] ?? $c->schedules()->latest('version')->value('id');
        $sequence = 0;
        foreach ($rows as $row) {
            $sequence = max($sequence, $row['sequence']);
        }
        if ($have < $wanted) {
            $anchor = CarbonImmutable::parse($terms['first_due_date']);
            $months = match ($terms['frequency']) {
                'monthly' => 1, 'quarterly' => 3, 'yearly' => 12, default => 1,
            };
            for ($i = $have; $i < $wanted; $i++) {
                $sequence++;
                $rows[] = [
                    'id' => null,
                    'kind' => 'sale_installment',
                    'label' => 'قسط البيع '.($i + 1),
                    'due_date' => $anchor->addMonthsNoOverflow($i * $months)->toDateString(),
                    'amount' => 0,
                    'paid' => 0,
                    'period_start' => null,
                    'period_end' => null,
                    'sequence' => $sequence,
                    'schedule_id' => $scheduleId,
                    'void' => false,
                    'created' => true,
                ];
            }
        } elseif ($have > $wanted) {
            $extra = array_reverse($installments);
            $need = $have - $wanted;
            foreach ($extra as $row) {
                if ($need <= 0) {
                    break;
                }
                if ($row['paid'] > 0) {
                    $this->fail('installments_count', 'لا يمكن تقليل عدد الأقساط بينما أقساط لاحقة محصّلة. عدّل القيم يدويًا أو أبقِ العدد.');
                }
                foreach ($rows as &$candidate) {
                    if ($candidate['id'] === $row['id'] && $candidate['kind'] === 'sale_installment' && ! $candidate['created']) {
                        $candidate['void'] = true;
                        $need--;
                        break;
                    }
                    if ($candidate['created'] && $candidate['label'] === $row['label'] && $candidate['due_date'] === $row['due_date']) {
                        $candidate['void'] = true;
                        $need--;
                        break;
                    }
                }
                unset($candidate);
            }
        }
    }

    private function applyAmountScope(Contract $c, array $terms, string $scope, int $target, array $manual, array &$rows): void
    {
        if ($scope === 'settlement_line') {
            $sum = 0;
            foreach ($rows as $row) {
                if (! $row['void'] && $row['kind'] !== 'security_deposit') {
                    $sum += $row['amount'];
                }
            }
            $diff = $target - $sum;
            $existing = null;
            foreach ($rows as $i => $row) {
                if (! $row['void'] && $row['kind'] === 'adjustment') {
                    $existing = $i;
                    break;
                }
            }
            if ($diff === 0) {
                return;
            }
            if ($existing !== null) {
                $new = $rows[$existing]['amount'] + $diff;
                if ($new < $rows[$existing]['paid']) {
                    $this->fail('amount_scope', 'سطر التسوية لا يغطي المحصّل عليه. اختر توزيعًا أو تعديلًا يدويًا.');
                }
                if ($new <= 0) {
                    $rows[$existing]['void'] = $rows[$existing]['paid'] === 0;
                    $rows[$existing]['amount'] = max(0, $new);
                } else {
                    $rows[$existing]['amount'] = $new;
                }
            } else {
                if ($diff < 0) {
                    $this->fail('amount_scope', 'لتخفيض الالتزام استخدم توزيع غير المدفوع أو التعديل اليدوي، أو أضف تسوية بعد تخفيض الأقساط المفتوحة.');
                }
                $scheduleId = $rows[0]['schedule_id'] ?? $c->schedules()->latest('version')->value('id');
                $sequence = 0;
                foreach ($rows as $row) {
                    $sequence = max($sequence, $row['sequence']);
                }
                $rows[] = [
                    'id' => null,
                    'kind' => 'adjustment',
                    'label' => 'تسوية مالية',
                    'due_date' => $terms['start_date'],
                    'amount' => $diff,
                    'paid' => 0,
                    'period_start' => null,
                    'period_end' => null,
                    'sequence' => $sequence + 1,
                    'schedule_id' => $scheduleId,
                    'void' => false,
                    'created' => true,
                ];
            }

            return;
        }

        if ($scope === 'manual_dues') {
            $byId = [];
            foreach ($manual as $entry) {
                $byId[(int) $entry['id']] = $entry;
            }
            foreach ($rows as &$row) {
                if ($row['void'] || $row['kind'] === 'security_deposit' || $row['created']) {
                    continue;
                }
                if (! isset($byId[$row['id']])) {
                    $this->fail('dues', 'أرسل كل الاستحقاقات القائمة عند التعديل اليدوي.');
                }
                $entry = $byId[$row['id']];
                $row['amount'] = Money::cents($entry['amount']);
                if ($row['amount'] <= 0) {
                    $this->fail('dues', 'قيمة الاستحقاق يجب أن تكون أكبر من صفر.');
                }
                if (! empty($entry['due_date'])) {
                    $row['due_date'] = $entry['due_date'];
                }
            }
            unset($row);

            return;
        }

        $locked = 0;
        $openIndexes = [];
        foreach ($rows as $i => $row) {
            if ($row['void'] || $row['kind'] === 'security_deposit') {
                continue;
            }
            if ($row['paid'] >= $row['amount'] && $row['paid'] > 0 && ! $row['created']) {
                $locked += $row['amount'];
            } else {
                $openIndexes[] = $i;
            }
        }
        if ($locked > $target) {
            $this->fail('amount', 'المحصّل على أقساط مسددة بالكامل أكبر من القيمة الجديدة. عدّل الأقساط المدفوعة يدويًا لمعالجة الزيادة.');
        }
        $remaining = $target - $locked;
        $count = count($openIndexes);
        if ($count === 0) {
            if ($remaining !== 0) {
                $this->fail('amount', 'لا توجد استحقاقات مفتوحة لإعادة التوزيع.');
            }

            return;
        }
        if ($c->sale_mode === 'installments') {
            $downIndex = null;
            foreach ($openIndexes as $i) {
                if ($rows[$i]['kind'] === 'down_payment') {
                    $downIndex = $i;
                    break;
                }
            }
            if ($downIndex !== null && $rows[$downIndex]['paid'] === 0) {
                $down = Money::cents($terms['down_payment']);
                if ($down > $remaining) {
                    $this->fail('down_payment', 'المقدم أكبر من المتبقي بعد الأقساط المسددة.');
                }
                $rows[$downIndex]['amount'] = $down;
                $remaining -= $down;
                $openIndexes = array_values(array_filter($openIndexes, fn ($i) => $i !== $downIndex));
                $count = count($openIndexes);
            }
        }
        if ($count === 0) {
            if ($remaining !== 0) {
                $this->fail('amount', 'لا يمكن مطابقة القيمة الجديدة دون استحقاقات مفتوحة.');
            }

            return;
        }
        $base = intdiv($remaining, $count);
        $extra = $remaining % $count;
        foreach ($openIndexes as $n => $i) {
            $share = $base + ($n < $extra ? 1 : 0);
            if ($share < $rows[$i]['paid']) {
                $this->fail('amount', 'قيمة أحد الأقساط المفتوحة ستقل عن المحصّل عليه. استخدم التعديل اليدوي لمعالجة الزيادة.');
            }
            $rows[$i]['amount'] = $share;
        }
    }

    private function applyDeposit(array $terms, array &$rows): void
    {
        $target = Money::cents($terms['security_deposit']);
        $index = null;
        foreach ($rows as $i => $row) {
            if (! $row['void'] && $row['kind'] === 'security_deposit') {
                $index = $i;
                break;
            }
        }
        if ($index === null) {
            if ($target <= 0) {
                return;
            }
            $scheduleId = $rows[0]['schedule_id'] ?? null;
            $sequence = 0;
            foreach ($rows as $row) {
                $sequence = max($sequence, $row['sequence']);
            }
            $rows[] = [
                'id' => null,
                'kind' => 'security_deposit',
                'label' => 'تأمين الإيجار (منفصل)',
                'due_date' => $terms['start_date'],
                'amount' => $target,
                'paid' => 0,
                'period_start' => null,
                'period_end' => null,
                'sequence' => $sequence + 1,
                'schedule_id' => $scheduleId,
                'void' => false,
                'created' => true,
            ];

            return;
        }
        if ($target === 0 && $rows[$index]['paid'] === 0) {
            $rows[$index]['void'] = true;
            $rows[$index]['amount'] = 0;

            return;
        }
        if ($target < $rows[$index]['paid'] && $target > 0) {
            $rows[$index]['amount'] = $target;

            return;
        }
        $rows[$index]['amount'] = $target;
    }

    private function planReallocate(Contract $c, array $rows): array
    {
        $remaining = [];
        foreach ($rows as $row) {
            if ($row['void'] || ! $row['id']) {
                continue;
            }
            $remaining[$row['id']] = max(0, $row['amount'] - $row['paid']);
        }
        $moves = [];
        foreach ($rows as $row) {
            if ($row['void'] || ! $row['id']) {
                continue;
            }
            $extra = max(0, $row['paid'] - $row['amount']);
            if ($extra <= 0) {
                continue;
            }
            $due = $c->dues->firstWhere('id', $row['id']);
            $allocs = $due->allocations->filter(fn ($a) => $a->payment->status === 'posted')->sortByDesc('id')->values();
            foreach ($allocs as $alloc) {
                if ($extra <= 0) {
                    break;
                }
                $take = min($extra, Money::cents($alloc->amount));
                while ($take > 0) {
                    $targetId = null;
                    foreach ($remaining as $id => $left) {
                        if ($left > 0) {
                            $targetId = $id;
                            break;
                        }
                    }
                    if (! $targetId) {
                        $this->fail('surplus_action', 'لا توجد استحقاقات مفتوحة لاستيعاب الزيادة. اختر رصيدًا دائنًا أو رد مبلغ.');
                    }
                    $chunk = min($take, $remaining[$targetId]);
                    $moves[] = ['allocation_id' => $alloc->id, 'from_due_id' => $row['id'], 'to_due_id' => $targetId, 'amount' => $chunk];
                    $remaining[$targetId] -= $chunk;
                    $take -= $chunk;
                    $extra -= $chunk;
                }
            }
        }

        return $moves;
    }

    private function planRefundAllocations(Contract $c, array $rows, array &$moves): void
    {
        foreach ($rows as $row) {
            if ($row['void'] || ! $row['id']) {
                continue;
            }
            $extra = max(0, $row['paid'] - $row['amount']);
            if ($extra <= 0) {
                continue;
            }
            $due = $c->dues->firstWhere('id', $row['id']);
            foreach ($due->allocations->filter(fn ($a) => $a->payment->status === 'posted')->sortByDesc('id') as $alloc) {
                if ($extra <= 0) {
                    break;
                }
                $take = min($extra, Money::cents($alloc->amount));
                $moves[] = ['allocation_id' => $alloc->id, 'from_due_id' => $row['id'], 'to_due_id' => null, 'amount' => $take];
                $extra -= $take;
            }
            if ($extra > 0) {
                $this->fail('surplus_action', 'تعذر فصل الزيادة عن التخصيصات الحالية.');
            }
        }
    }

    private function payload(array $plan): array
    {
        $afterDues = [];
        foreach ($plan['rows'] as $row) {
            if ($row['void']) {
                continue;
            }
            $afterDues[] = [
                'id' => $row['id'],
                'kind' => $row['kind'],
                'label' => $row['label'],
                'due_date' => $row['due_date'],
                'amount' => Money::decimal($row['amount']),
                'paid' => Money::decimal($row['paid']),
                'remaining' => Money::decimal(max(0, $row['amount'] - $row['paid'])),
                'created' => $row['created'],
            ];
        }
        $beforePrincipal = 0;
        foreach ($plan['before_rows'] as $due) {
            if (($due['kind'] ?? '') !== 'security_deposit') {
                $beforePrincipal += Money::cents($due['amount']);
            }
        }

        return [
            'before' => [
                'amount' => $plan['before_terms']['amount'],
                'start_date' => $plan['before_terms']['start_date'],
                'end_date' => $plan['before_terms']['end_date'],
                'down_payment' => $plan['before_terms']['down_payment'],
                'security_deposit' => $plan['before_terms']['security_deposit'],
                'frequency' => $plan['before_terms']['frequency'],
                'installments_count' => $plan['before_terms']['installments_count'],
                'first_due_date' => $plan['before_terms']['first_due_date'],
                'credit_balance' => $plan['before_terms']['credit_balance'],
                'collected' => Money::decimal($plan['collected']),
                'remaining' => Money::decimal(max(0, $beforePrincipal - $plan['collected'])),
                'dues' => $plan['before_rows'],
            ],
            'after' => [
                'amount' => $plan['terms']['amount'],
                'start_date' => $plan['terms']['start_date'],
                'end_date' => $plan['terms']['end_date'],
                'down_payment' => $plan['terms']['down_payment'],
                'security_deposit' => $plan['terms']['security_deposit'],
                'frequency' => $plan['terms']['frequency'],
                'installments_count' => $plan['terms']['installments_count'],
                'first_due_date' => $plan['terms']['first_due_date'],
                'credit_balance' => Money::decimal(Money::cents($plan['before_terms']['credit_balance']) + $plan['credit']),
                'collected' => Money::decimal($plan['collected']),
                'remaining' => Money::decimal($plan['remaining']),
                'dues' => $afterDues,
            ],
            'totals' => [
                'collected' => Money::decimal($plan['collected']),
                'remaining' => Money::decimal($plan['remaining']),
                'surplus' => Money::decimal($plan['surplus']),
                'credit' => Money::decimal($plan['credit']),
                'refund' => Money::decimal($plan['refund']),
            ],
            'options' => [
                'date_scope' => $plan['date_scope'],
                'amount_scope' => $plan['amount_scope'],
                'surplus_action' => $plan['surplus_action'],
            ],
            'warnings' => $plan['warnings'],
        ];
    }

    private function applyPlan(Contract $c, array $plan, array $input, int $userId, string $hash): void
    {
        $c->update([
            'amount' => $plan['terms']['amount'],
            'start_date' => $plan['terms']['start_date'],
            'end_date' => $plan['terms']['end_date'],
            'effective_end_date' => $plan['terms']['effective_end_date'],
            'down_payment' => $plan['terms']['down_payment'],
            'security_deposit' => $plan['terms']['security_deposit'],
            'frequency' => $plan['terms']['frequency'],
            'installments_count' => $plan['terms']['installments_count'],
            'first_due_date' => $plan['terms']['first_due_date'],
            'notes' => $plan['terms']['notes'],
            'credit_balance' => Money::decimal(Money::cents($c->credit_balance) + $plan['credit']),
        ]);

        foreach ($plan['rows'] as $row) {
            if ($row['created']) {
                if ($row['void']) {
                    continue;
                }
                $maxSeq = (int) Due::where('schedule_id', $row['schedule_id'])->max('sequence');
                Due::create([
                    'schedule_id' => $row['schedule_id'],
                    'contract_id' => $c->id,
                    'sequence' => $maxSeq + 1,
                    'kind' => $row['kind'],
                    'label' => $row['label'],
                    'due_date' => $row['due_date'],
                    'amount' => Money::decimal($row['amount']),
                    'period_start' => $row['period_start'],
                    'period_end' => $row['period_end'],
                ]);
                continue;
            }
            $due = Due::findOrFail($row['id']);
            if ($row['void']) {
                $due->update(['is_void' => true, 'voided_at' => now(), 'void_reason' => $input['reason']]);
                continue;
            }
            $due->update([
                'due_date' => $row['due_date'],
                'amount' => Money::decimal($row['amount']),
                'period_start' => $row['period_start'],
                'period_end' => $row['period_end'],
                'label' => $row['label'],
            ]);
        }

        $this->applyMoves($plan['allocation_moves']);

        $payload = $this->payload($plan);
        $revision = ContractRevision::create([
            'contract_id' => $c->id,
            'user_id' => $userId,
            'reason' => $input['reason'],
            'request_key' => $input['request_key'],
            'request_hash' => $hash,
            'before' => $payload['before'],
            'after' => $payload['after'],
            'options' => $payload['options'],
        ]);

        if ($plan['refund'] > 0) {
            $this->cash->post(
                'out',
                $plan['refund'],
                now()->toDateString(),
                'contract_refund',
                $revision->id,
                'رد فرق تعديل العقد '.$c->number,
                $input['reason']
            );
        }

        ContractEvent::create([
            'contract_id' => $c->id,
            'user_id' => $userId,
            'action' => 'terms_revised',
            'from_status' => $c->status,
            'to_status' => $c->status,
            'reason' => $input['reason'],
            'revision_id' => $revision->id,
            'request_key' => $input['request_key'],
            'request_hash' => $hash,
        ]);
    }

    private function applyMoves(array $moves): void
    {
        $grouped = [];
        foreach ($moves as $move) {
            $grouped[$move['allocation_id']][] = $move;
        }
        foreach ($grouped as $allocationId => $items) {
            $alloc = PaymentAllocation::lockForUpdate()->findOrFail($allocationId);
            $left = Money::cents($alloc->amount);
            foreach ($items as $move) {
                $chunk = $move['amount'];
                $left -= $chunk;
                if ($move['to_due_id']) {
                    $existing = PaymentAllocation::where('payment_id', $alloc->payment_id)->where('due_id', $move['to_due_id'])->first();
                    if ($existing) {
                        $existing->update(['amount' => Money::decimal(Money::cents($existing->amount) + $chunk)]);
                    } else {
                        PaymentAllocation::create([
                            'payment_id' => $alloc->payment_id,
                            'due_id' => $move['to_due_id'],
                            'amount' => Money::decimal($chunk),
                        ]);
                    }
                }
            }
            if ($left < 0) {
                $this->fail('surplus_action', 'تعذر إعادة توزيع التخصيصات.');
            }
            if ($left === 0) {
                $alloc->delete();
            } else {
                $alloc->update(['amount' => Money::decimal($left)]);
            }
        }
    }
}
