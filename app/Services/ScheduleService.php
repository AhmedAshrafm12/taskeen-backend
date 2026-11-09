<?php

namespace App\Services;

use App\Models\Contract;
use App\Models\ContractEvent;
use App\Models\Schedule;
use App\Models\Unit;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class ScheduleService
{
    public const POLICY = 'الفترة الأخيرة للإيجار تُحسب بنسبة أيامها إلى أيام فترة السداد الكاملة، مع التقريب لأقرب قرش. تواريخ الأشهر تُحسب من تاريخ البداية الأصلي دون تراكم انحراف.';

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['schedule' => $message]);
    }

    public function hasAllocations(Contract $contract): bool
    {
        return $contract->dues()->whereHas('allocations')->exists();
    }

    public function hasPostedAllocations(Contract $contract): bool
    {
        return $contract->dues()->whereHas('allocations', function ($q) {
            $q->whereHas('payment', fn ($payment) => $payment->where('status', 'posted'));
        })->exists();
    }

    public function automatic(Contract $c): array
    {
        $rows = [];
        $add = function ($kind, $label, $date, $cents, $start = null, $end = null) use (&$rows) {
            if ($cents > 0) {
                $rows[] = ['kind' => $kind, 'label' => $label, 'due_date' => $date, 'amount' => Money::decimal($cents), 'period_start' => $start, 'period_end' => $end];
            }
        };
        $start = $c->start_date->toDateString();
        if ($c->type === 'sale') {
            $total = Money::cents($c->amount);
            if ($c->sale_mode === 'cash') {
                $add('sale_cash', 'ثمن البيع النقدي', $start, $total);
            } else {
                $down = Money::cents($c->down_payment);
                $add('down_payment', 'مقدم البيع', $start, $down);
                $count = (int) $c->installments_count;
                $remaining = $total - $down;
                if ($count < 1 || $count > 600 || $remaining < $count) {
                    $this->fail('شروط التقسيط لا تسمح بإنشاء جدول صحيح.');
                }
                $anchor = CarbonImmutable::parse($c->first_due_date->toDateString());
                $months = $this->months($c->frequency);
                for ($i = 0; $i < $count; $i++) {
                    $add('sale_installment', 'قسط البيع '.($i + 1), $anchor->addMonthsNoOverflow($i * $months)->toDateString(), intdiv($remaining, $count) + ($i < $remaining % $count ? 1 : 0));
                }
            }
        } else {
            $add('security_deposit', 'تأمين الإيجار (منفصل)', $start, Money::cents($c->security_deposit));
            $anchor = CarbonImmutable::parse($start);
            $end = CarbonImmutable::parse($c->effective_end_date->toDateString());
            $months = $this->months($c->frequency);
            for ($i = 0; ; $i++) {
                $periodStart = $anchor->addMonthsNoOverflow($i * $months);
                if ($periodStart->gt($end)) {
                    break;
                }
                if ($i >= 600) {
                    $this->fail('مدة الإيجار تتجاوز ٦٠٠ فترة سداد.');
                }
                $next = $anchor->addMonthsNoOverflow(($i + 1) * $months);
                $periodEnd = $next->subDay()->min($end);
                $days = (int) $periodStart->diffInDays($periodEnd) + 1;
                $full = (int) $periodStart->diffInDays($next);
                $amount = intdiv(Money::cents($c->amount) * $days + intdiv($full, 2), $full);
                $add('rent', 'إيجار الفترة '.($i + 1), $periodStart->toDateString(), $amount, $periodStart->toDateString(), $periodEnd->toDateString());
            }
        }
        foreach ($rows as $row) {
            if (! preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/', $row['due_date']) || $row['due_date'] < '1000-01-01') {
                $this->fail('تاريخ أحد الاستحقاقات خارج النطاق المسموح.');
            }
        }

        return $rows;
    }

    private function months(?string $frequency): int
    {
        return match ($frequency) {
            'monthly' => 1,'quarterly' => 3,'yearly' => 12,default => throw ValidationException::withMessages(['frequency' => 'دورية السداد غير صحيحة.'])
        };
    }

    public function rows(Contract $c, string $mode, array $entries = []): array
    {
        $automatic = $this->automatic($c);
        if ($mode === 'automatic') {
            return $automatic;
        }
        if ($c->type === 'sale' && $c->sale_mode === 'cash') {
            $this->fail('البيع النقدي له استحقاق واحد ثابت ولا يحتاج جدولًا مخصصًا.');
        }
        $kind = $c->type === 'sale' ? 'sale_installment' : 'rent';
        $fixed = array_values(array_filter($automatic, fn ($r) => $r['kind'] !== $kind));
        $expected = array_sum(array_map(fn ($r) => $r['kind'] === $kind ? Money::cents($r['amount']) : 0, $automatic));
        if (! $entries || count($entries) > 600) {
            $this->fail('أدخل من ١ إلى ٦٠٠ استحقاق مخصص.');
        }
        if ($c->type === 'sale' && count($entries) !== (int) $c->installments_count) {
            $this->fail('عدد الأقساط المخصصة يجب أن يطابق عدد الأقساط المتفق عليه.');
        }
        $total = 0;
        $previous = null;
        foreach ($entries as $i => $e) {
            if ($e['due_date'] < $c->start_date->toDateString() || ($c->type === 'rental' && $e['due_date'] > $c->effective_end_date->toDateString())) {
                $this->fail('تاريخ الاستحقاق المخصص يجب أن يقع داخل فترة العقد.');
            }
            if ($previous && $e['due_date'] < $previous) {
                $this->fail('رتّب الاستحقاقات المخصصة حسب التاريخ.');
            }
            if ($c->type === 'sale' && $i === 0 && $e['due_date'] !== $c->first_due_date->toDateString()) {
                $this->fail('أول قسط مخصص يجب أن يطابق تاريخ أول استحقاق المتفق عليه.');
            }
            $previous = $e['due_date'];
            $total += Money::cents($e['amount']);
            $fixed[] = ['kind' => $kind, 'label' => ($kind === 'rent' ? 'إيجار مخصص ' : 'قسط مخصص ').($i + 1), 'due_date' => $e['due_date'], 'amount' => Money::decimal(Money::cents($e['amount'])), 'period_start' => null, 'period_end' => null];
        }
        if ($total !== $expected) {
            $this->fail('إجمالي الاستحقاقات المخصصة يجب أن يساوي '.Money::decimal($expected).' جنيه دون تغيير قيمة العقد أو التأمين أو المقدم.');
        }
        usort($fixed, fn ($a, $b) => strcmp($a['due_date'], $b['due_date']));

        return $fixed;
    }

    public function ensure(Contract $contract, int $userId): ?Schedule
    {
        if (in_array($contract->status, ['draft', 'cancelled'])) {
            return null;
        }
        if ($existing = $contract->schedules()->latest('version')->first()) {
            return $existing;
        }

        return $this->write($contract, 'automatic', $this->automatic($contract), $userId, 'إنشاء جدول من شروط العقد');
    }

    private function write(Contract $c, string $mode, array $rows, int $userId, string $reason, ?array $request = null): Schedule
    {
        $version = (int) $c->schedules()->max('version') + 1;
        $c->dues()->where('is_void', false)->update(['is_void' => true, 'voided_at' => now(), 'void_reason' => $reason]);
        $schedule = $c->schedules()->create(['version' => $version, 'mode' => $mode, 'reason' => $reason, 'user_id' => $userId, 'request_key' => $request['request_key'] ?? null, 'request_hash' => $request['hash'] ?? null]);
        foreach ($rows as $i => $row) {
            $schedule->dues()->create(array_merge($row, ['contract_id' => $c->id, 'sequence' => $i + 1]));
        }

        return $schedule;
    }

    public function replace(Contract $original, array $input, int $userId): Schedule
    {
        $hash = hash('sha256', json_encode($input));

        return DB::transaction(function () use ($original, $input, $userId, $hash) {
            Unit::lockForUpdate()->findOrFail($original->unit_id);
            $c = Contract::lockForUpdate()->findOrFail($original->id);
            if ($existing = Schedule::where('request_key', $input['request_key'])->first()) {
                if ($existing->contract_id !== $c->id || $existing->request_hash !== $hash) {
                    throw new ConflictHttpException;
                }

                return $existing;
            }
            if (in_array($c->status, ['draft', 'cancelled'])) {
                $this->fail('لا يمكن إنشاء جدول لمسودة أو عقد ملغى.');
            }
            if ((int) $c->schedules()->max('version') !== (int) $input['expected_version']) {
                $this->fail('الجدول تغير بالفعل. حدّث الصفحة وراجع أحدث نسخة.');
            }
            if ($this->hasAllocations($c)) {
                $this->fail('الجدول محمي لوجود تخصيصات مدفوعات، حتى بعد عكسها. لا يتاح تعديل جدول مرتبط بمدفوعات مباشرة.');
            }
            $rows = $this->rows($c, $input['mode'], $input['entries'] ?? []);
            $schedule = $this->write($c, $input['mode'], $rows, $userId, $input['reason'], ['request_key' => $input['request_key'], 'hash' => $hash]);
            ContractEvent::create(['contract_id' => $c->id, 'user_id' => $userId, 'action' => 'schedule_replaced', 'from_status' => $c->status, 'to_status' => $c->status, 'reason' => $input['reason']]);

            return $schedule;
        }, 3);
    }

    public function beforeTransition(Contract $c, string $action): void
    {
        // Cancel only while posted collections still exist. Fully reversed payments unlock cancel.
        // Schedule rewrite stays protected by any allocation history, including reversed ones.
        if ($action === 'cancel' && $this->hasPostedAllocations($c)) {
            $this->fail('لا يمكن إلغاء عقد عليه دفعات سارية. اعكس الإيصالات أولًا أو أنهِ الإيجار مع تسوية المستحقات.');
        }
        if ($action === 'terminate' && $c->schedules()->latest('version')->value('mode') === 'custom') {
            $this->fail('أعد الجدول المخصص إلى الجدول التلقائي قبل إنهاء الإيجار، بعد مراجعة الأرقام.');
        }
    }

    public function afterTransition(Contract $c, string $action, int $userId, string $reason, array $settlement = []): void
    {
        if ($action === 'activate') {
            $this->ensure($c, $userId);
        }
        if ($action === 'cancel') {
            $c->dues()->where('is_void', false)->update(['is_void' => true, 'voided_at' => now(), 'void_reason' => $reason]);
        }
        if ($action === 'terminate') {
            $this->applyTerminationSettlement(
                $c,
                $userId,
                $reason,
                $settlement['mode'] ?? 'keep',
                $settlement['waive_amount'] ?? null
            );
        }
    }

    public function terminationSnapshot(Contract $c, string $effectiveDate): array
    {
        $clone = clone $c;
        $clone->effective_end_date = $effectiveDate;
        $rows = $this->automatic($clone);
        $today = today()->toDateString();
        $principalRemaining = 0;
        $principalOverdue = 0;
        $depositRemaining = 0;
        $futurePrincipal = 0;
        foreach ($c->dues()->where('is_void', false)->with('allocations.payment')->orderBy('due_date')->orderBy('id')->get() as $due) {
            $remaining = max(0, Money::cents($due->amount) - $due->paidCents());
            if ($remaining <= 0) {
                continue;
            }
            if ($due->kind === 'security_deposit') {
                $depositRemaining += $remaining;
                continue;
            }
            $periodStart = $due->period_start?->toDateString();
            $isFuture = ($periodStart && $periodStart > $effectiveDate)
                || (! $periodStart && $due->due_date->toDateString() > $effectiveDate);
            if ($isFuture) {
                $futurePrincipal += $remaining;
            } else {
                $principalRemaining += $remaining;
                if ($due->due_date->toDateString() < $today) {
                    $principalOverdue += $remaining;
                }
            }
        }
        $afterKeepPrincipal = $this->hasAllocations($c)
            ? $principalRemaining
            : array_sum(array_map(fn ($r) => $r['kind'] === 'security_deposit' ? 0 : Money::cents($r['amount']), $rows));

        return [
            'has_allocations' => $this->hasAllocations($c),
            'principal_remaining' => Money::decimal($principalRemaining + $futurePrincipal),
            'principal_overdue' => Money::decimal($principalOverdue),
            'deposit_remaining' => Money::decimal($depositRemaining),
            'future_principal_to_cancel' => Money::decimal($futurePrincipal),
            'after_keep_principal' => Money::decimal($afterKeepPrincipal),
            'after_keep_deposit' => Money::decimal($depositRemaining),
            'preview_principal_total' => Money::decimal(array_sum(array_map(fn ($r) => $r['kind'] === 'security_deposit' ? 0 : Money::cents($r['amount']), $rows))),
            'preview_deposit_total' => Money::decimal(array_sum(array_map(fn ($r) => $r['kind'] === 'security_deposit' ? Money::cents($r['amount']) : 0, $rows))),
            'rows' => $rows,
        ];
    }

    private function applyTerminationSettlement(Contract $c, int $userId, string $reason, string $mode, ?string $waiveAmount): void
    {
        if (! $this->hasAllocations($c)) {
            $this->write($c, 'automatic', $this->automatic($c), $userId, 'تعديل الجدول بسبب إنهاء الإيجار: '.$reason);
        } else {
            $this->voidFuturePrincipalAfter($c, $c->effective_end_date->toDateString(), 'إلغاء التزامات مستقبلية بسبب إنهاء الإيجار: '.$reason);
        }

        if ($mode === 'waive') {
            $this->waiveOpenPrincipal($c, 'إعفاء من المستحقات عند إنهاء الإيجار: '.$reason);
        } elseif ($mode === 'partial') {
            if ($waiveAmount === null || Money::cents($waiveAmount) <= 0) {
                $this->fail('أدخل مبلغًا موجبًا للإعفاء في التسوية الجزئية.');
            }
            $this->waivePrincipalAmount($c, Money::cents($waiveAmount), 'تسوية جزئية عند إنهاء الإيجار: '.$reason);
        }
    }

    private function voidFuturePrincipalAfter(Contract $c, string $end, string $reason): void
    {
        $c->dues()->where('is_void', false)
            ->where('kind', '!=', 'security_deposit')
            ->where(function ($q) use ($end) {
                $q->where('period_start', '>', $end)
                    ->orWhere(function ($inner) use ($end) {
                        $inner->whereNull('period_start')->where('due_date', '>', $end);
                    });
            })
            ->update(['is_void' => true, 'voided_at' => now(), 'void_reason' => $reason]);
    }

    private function waiveOpenPrincipal(Contract $c, string $reason): void
    {
        foreach ($c->dues()->where('is_void', false)->where('kind', '!=', 'security_deposit')->with('allocations.payment')->orderBy('due_date')->orderBy('id')->get() as $due) {
            if (max(0, Money::cents($due->amount) - $due->paidCents()) > 0) {
                $due->update(['is_void' => true, 'voided_at' => now(), 'void_reason' => $reason]);
            }
        }
    }

    private function waivePrincipalAmount(Contract $c, int $waiveCents, string $reason): void
    {
        $left = $waiveCents;
        // Prefer voiding fully unpaid dues from newest to oldest so older arrears stay collectible first.
        $dues = $c->dues()->where('is_void', false)->where('kind', '!=', 'security_deposit')
            ->with('allocations.payment')->orderByDesc('due_date')->orderByDesc('id')->get();
        foreach ($dues as $due) {
            if ($left <= 0) {
                break;
            }
            $paid = $due->paidCents();
            $remaining = max(0, Money::cents($due->amount) - $paid);
            if ($remaining <= 0 || $paid > 0) {
                continue;
            }
            if ($remaining <= $left) {
                $due->update(['is_void' => true, 'voided_at' => now(), 'void_reason' => $reason]);
                $left -= $remaining;
            }
        }
        if ($left === $waiveCents) {
            $this->fail('لا توجد مستحقات غير مدفوعة بالكامل يمكن إعفاؤها بهذا المبلغ.');
        }
        if ($left > 0) {
            $this->fail('مبلغ الإعفاء أكبر من المستحقات القابلة للإعفاء بالكامل دون كسر بند مدفوع جزئيًا. خفّض المبلغ أو سجّل دفعة أولًا.');
        }
    }
}
