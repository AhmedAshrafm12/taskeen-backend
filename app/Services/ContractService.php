<?php

namespace App\Services;

use App\Models\Building;
use App\Models\Contract;
use App\Models\ContractEvent;
use App\Models\Unit;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class ContractService
{
    public static function cents($value): int
    {
        return Money::cents($value);
    }

    private function fail(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }

    public function create(array $input, int $userId): Contract
    {
        $hash = hash('sha256', json_encode($input));

        return DB::transaction(function () use ($input, $userId, $hash) {
            $unit = Unit::lockForUpdate()->findOrFail($input['unit_id']);
            if ($existing = Contract::where('request_key', $input['request_key'])->first()) {
                if ($existing->request_hash !== $hash) {
                    throw new ConflictHttpException('Request key already used');
                }

                return $existing;
            }
            $data = $this->terms($unit, $input);
            if ($data['status'] === 'active') {
                $data['activated_at'] = now();
            }
            $data['request_hash'] = $hash;
            $contract = Contract::create($data);
            $this->event($contract, $userId, 'created', null, $contract->status);
            app(ScheduleService::class)->ensure($contract, $userId);
            if (! empty($data['renewed_from_id'])) {
                $source = Contract::findOrFail($data['renewed_from_id']);
                $this->event($source, $userId, 'renewed', $source->status, $source->status, 'تجديد مرتبط بالعقد '.$contract->number);
            }

            return $contract;
        }, 3);
    }

    public function preview(array $input): array
    {
        return DB::transaction(function () use ($input) {
            $unit = Unit::lockForUpdate()->findOrFail($input['unit_id']);
            $contract = new Contract($this->terms($unit, $input));
            $rows = app(ScheduleService::class)->automatic($contract);

            return [
                'rows' => $rows, 'policy' => ScheduleService::POLICY,
                'principal_total' => Money::decimal(array_sum(array_map(fn ($row) => $row['kind'] === 'security_deposit' ? 0 : Money::cents($row['amount']), $rows))),
                'deposit_total' => Money::decimal(array_sum(array_map(fn ($row) => $row['kind'] === 'security_deposit' ? Money::cents($row['amount']) : 0, $rows))),
            ];
        }, 3);
    }

    private function terms(Unit $unit, array $input): array
    {
        $data = $input;
        $installments = $data['type'] === 'sale' && $data['sale_mode'] === 'installments';
        $data['down_payment'] = $data['down_payment'] ?? '0';
        $data['security_deposit'] = $data['security_deposit'] ?? '0';
        if (! $installments && self::cents($data['down_payment']) !== 0) {
            $this->fail('down_payment', 'المقدم يستخدم فقط مع البيع بالتقسيط. تسجيل التحصيل يتم من المدفوعات.');
        }
        if ($installments && self::cents($data['down_payment']) >= self::cents($data['amount'])) {
            $this->fail('down_payment', 'المقدم يجب أن يكون أقل من قيمة البيع بالتقسيط.');
        }
        if ($installments && self::cents($data['amount']) - self::cents($data['down_payment']) < (int) $data['installments_count']) {
            $this->fail('installments_count', 'عدد الأقساط لا يسمح بقسط أقل من قرش.');
        }
        if ($data['type'] === 'sale' && self::cents($data['security_deposit']) !== 0) {
            $this->fail('security_deposit', 'تأمين الإيجار لا يستخدم مع البيع.');
        }
        if (! $installments) {
            if (! empty($data['installments_count']) || ! empty($data['first_due_date'])) {
                $this->fail('installments_count', 'بيانات الأقساط تخص البيع بالتقسيط فقط.');
            }
            $data['installments_count'] = null;
            $data['first_due_date'] = null;
        }
        if ($data['type'] === 'sale' && ! $installments) {
            if (! empty($data['frequency'])) {
                $this->fail('frequency', 'البيع النقدي لا يحتاج دورية أقساط.');
            }
            $data['frequency'] = null;
        }
        $data['effective_end_date'] = $data['end_date'] ?? null;
        if (! empty($data['renewed_from_id'])) {
            $source = Contract::findOrFail($data['renewed_from_id']);
            if ($source->type !== 'rental' || ! in_array($source->status, ['active', 'ended']) || $data['type'] !== 'rental'
               || $source->unit_id != $data['unit_id'] || $source->customer_id != $data['customer_id']) {
                $this->fail('renewed_from_id', 'التجديد يجب أن يرتبط بعقد إيجار قائم لنفس الوحدة والعميل.');
            }
            if ($source->effective_end_date->copy()->addDay()->toDateString() !== $data['start_date']) {
                $this->fail('start_date', 'التجديد يبدأ في اليوم التالي لانتهاء العقد السابق.');
            }
            if (Contract::where('renewed_from_id', $source->id)->where('status', '!=', 'cancelled')->exists()) {
                $this->fail('renewed_from_id', 'يوجد تجديد مسجل بالفعل لهذا العقد.');
            }
        }
        if ($data['status'] === 'active') {
            $this->available($unit, $data);
        }

        return $data;
    }

    private function available(Unit $unit, array $data, ?int $ignore = null): void
    {
        if (! Building::lockForUpdate()->findOrFail($unit->building_id)->is_active) {
            $this->fail('unit_id', 'أعد تفعيل المبنى قبل تفعيل التعاقد.');
        }
        if (! Contract::where('unit_id', $unit->id)->whereNotNull('activated_at')->exists() && $unit->getRawOriginal('status') !== 'available') {
            $this->fail('unit_id', 'الوحدة مسجلة كمباعة أو مؤجرة. راجع السجلات قبل التعاقد.');
        }
        $booked = Contract::where('unit_id', $unit->id)->whereNotIn('status', ['draft', 'cancelled'])->when($ignore, fn ($q) => $q->whereKeyNot($ignore));
        if ($data['type'] === 'sale') {
            if ((clone $booked)->where('type', 'sale')->exists()) {
                $this->fail('unit_id', 'لا يمكن بيع الوحدة مرتين. يوجد عقد بيع محفوظ.');
            }
            if ((clone $booked)->where('type', 'rental')->whereDate('effective_end_date', '>=', $data['start_date'])->exists()) {
                $this->fail('start_date', 'تاريخ البيع يتعارض مع فترة إيجار مسجلة.');
            }
        } else {
            if ((clone $booked)->where('type', 'sale')->whereDate('start_date', '<=', $data['end_date'])->exists()) {
                $this->fail('unit_id', 'فترة الإيجار تتعارض مع بيع الوحدة.');
            }
            if ((clone $booked)->where('type', 'rental')->whereDate('start_date', '<=', $data['end_date'])->whereDate('effective_end_date', '>=', $data['start_date'])->exists()) {
                $this->fail('start_date', 'فترة الإيجار تتداخل مع عقد مسجل. تاريخ النهاية شامل.');
            }
        }
    }

    public function transition(Contract $original, array $input, int $userId): Contract
    {
        $hash = hash('sha256', json_encode($input));

        return DB::transaction(function () use ($original, $input, $userId, $hash) {
            $unit = Unit::lockForUpdate()->findOrFail($original->unit_id);
            $contract = Contract::lockForUpdate()->findOrFail($original->id);
            if ($event = ContractEvent::where('request_key', $input['request_key'])->first()) {
                if ($event->contract_id !== $contract->id || $event->request_hash !== $hash) {
                    throw new ConflictHttpException('Request key already used');
                }

                return $contract;
            }
            $from = $contract->status;
            $action = $input['action'];
            app(ScheduleService::class)->beforeTransition($contract, $action);
            if ($action === 'activate') {
                if ($from !== 'draft') {
                    $this->fail('action', 'يمكن تفعيل المسودة فقط.');
                }
                $data = $contract->attributesToArray();
                $data['start_date'] = $contract->start_date->toDateString();
                $data['end_date'] = $contract->end_date?->toDateString();
                $this->available($unit, $data, $contract->id);
                if ($contract->renewed_from_id && Contract::findOrFail($contract->renewed_from_id)->status === 'cancelled') {
                    $this->fail('action', 'تم إلغاء العقد الأصلي. أنشئ تعاقدًا جديدًا.');
                }
                $contract->status = 'active';
                $contract->activated_at = now();
            } elseif ($action === 'cancel') {
                if (! in_array($from, ['draft', 'active']) || $contract->effective_status === 'ended') {
                    $this->fail('action', 'لا يمكن إلغاء عقد مكتمل أو منتهٍ أو ملغى.');
                }
                if (Contract::where('renewed_from_id', $contract->id)->where('status', '!=', 'cancelled')->exists()) {
                    $this->fail('action', 'ألغِ التجديد المرتبط أولًا.');
                }
                $contract->status = 'cancelled';
            } elseif ($action === 'terminate') {
                if ($contract->type !== 'rental' || $from !== 'active' || $contract->effective_status !== 'active') {
                    $this->fail('action', 'إنهاء الإيجار متاح لعقد إيجار نشط فقط.');
                }
                $date = $input['effective_date'] ?? null;
                if (! $date || $date < $contract->start_date->toDateString() || $date > $contract->end_date->toDateString() || $date > today()->toDateString()) {
                    $this->fail('effective_date', 'اختر تاريخ إنهاء داخل مدة العقد وحتى اليوم.');
                }
                if (Contract::where('renewed_from_id', $contract->id)->where('status', '!=', 'cancelled')->exists()) {
                    $this->fail('action', 'ألغِ التجديد المرتبط أولًا قبل تغيير فترة العقد الأصلي.');
                }
                $contract->effective_end_date = $date;
                $contract->status = 'ended';
            } elseif ($action === 'complete') {
                if ($from !== 'active') {
                    $this->fail('action', 'يمكن إتمام العقد النشط فقط.');
                }
                if ($contract->start_date->gt(today()) || ($contract->type === 'rental' && $contract->effective_end_date->gt(today()))) {
                    $this->fail('action', 'لا يمكن إتمام العقد قبل موعده.');
                }
                $contract->status = $contract->type === 'sale' ? 'completed' : 'ended';
            }
            $contract->save();
            $settlement = [];
            if ($action === 'terminate') {
                $settlement = [
                    'mode' => $input['settlement_mode'] ?? 'keep',
                    'waive_amount' => $input['waive_amount'] ?? null,
                ];
            }
            app(ScheduleService::class)->afterTransition($contract, $action, $userId, $input['reason'], $settlement);
            $eventReason = $input['reason'];
            if ($action === 'terminate') {
                $modeLabel = match ($settlement['mode']) {
                    'waive' => 'إعفاء من المستحقات',
                    'partial' => 'تسوية جزئية'.($settlement['waive_amount'] ? ' (إعفاء '.$settlement['waive_amount'].')' : ''),
                    default => 'الاحتفاظ بالمستحقات',
                };
                $eventReason = $input['reason'].' — تسوية: '.$modeLabel;
            }
            $event = $this->event($contract, $userId, $action, $from, $contract->status, $eventReason, $input['effective_date'] ?? null);
            $event->update(['request_key' => $input['request_key'], 'request_hash' => $hash]);

            return $contract;
        }, 3);
    }

    private function event(Contract $contract, int $user, string $action, ?string $from, string $to, ?string $reason = null, ?string $date = null): ContractEvent
    {
        return ContractEvent::create(['contract_id' => $contract->id, 'user_id' => $user, 'action' => $action, 'from_status' => $from, 'to_status' => $to, 'reason' => $reason, 'effective_date' => $date]);
    }
}
