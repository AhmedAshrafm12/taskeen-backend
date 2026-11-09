<?php

namespace App\Http\Controllers;

use App\Http\Requests\ScheduleRequest;
use App\Models\Contract;
use App\Services\ScheduleService;
use App\Support\Money;
use Illuminate\Validation\ValidationException;

class ScheduleController extends Controller
{
    public function show(Contract $contract, ScheduleService $service)
    {
        $latest = $contract->schedules()->latest('version')->first();

        return response()->json(['data' => [
            'entries' => $latest ? $latest->dues()->where('is_void', false)->whereIn('kind', ['rent', 'sale_installment'])->orderBy('sequence')->get(['due_date', 'amount'])->map(fn ($due) => ['due_date' => $due->due_date->toDateString(), 'amount' => $due->amount]) : [],
            'version' => $latest?->version ?? 0, 'mode' => $latest?->mode,
            'can_replace' => ! in_array($contract->status, ['draft', 'cancelled']) && ! $service->hasAllocations($contract),
            'has_allocations' => $service->hasAllocations($contract), 'policy' => ScheduleService::POLICY,
            'revisions' => $contract->schedules()->latest('version')->get(['id', 'version', 'mode', 'reason', 'created_at']),
        ]]);
    }

    public function preview(ScheduleRequest $request, Contract $contract, ScheduleService $service)
    {
        if (in_array($contract->status, ['draft', 'cancelled'])) {
            throw ValidationException::withMessages(['schedule' => 'المسودات والعقود الملغاة لا تنشئ مستحقات.']);
        }
        $data = $request->validated();
        if (! empty($data['termination_date'])) {
            $date = $data['termination_date'];
            if ($contract->type !== 'rental' || $contract->status !== 'active' || $contract->effective_status !== 'active' || $data['mode'] !== 'automatic' || $date < $contract->start_date->toDateString() || $date > $contract->end_date->toDateString() || $date > today()->toDateString()) {
                throw ValidationException::withMessages(['termination_date' => 'اختر تاريخ إنهاء داخل مدة الإيجار وحتى اليوم.']);
            }
            $service->beforeTransition($contract, 'terminate');
            $snap = $service->terminationSnapshot($contract, $date);
            $rows = $snap['rows'];

            return response()->json(['data' => [
                'rows' => $rows,
                'expected_version' => (int) $contract->schedules()->max('version'),
                'policy' => ScheduleService::POLICY,
                'principal_total' => $snap['preview_principal_total'],
                'deposit_total' => $snap['preview_deposit_total'],
                'settlement' => [
                    'has_allocations' => $snap['has_allocations'],
                    'principal_remaining' => $snap['principal_remaining'],
                    'principal_overdue' => $snap['principal_overdue'],
                    'deposit_remaining' => $snap['deposit_remaining'],
                    'future_principal_to_cancel' => $snap['future_principal_to_cancel'],
                    'after_keep_principal' => $snap['after_keep_principal'],
                    'after_keep_deposit' => $snap['after_keep_deposit'],
                ],
            ]]);
        }
        $rows = $service->rows($contract, $data['mode'], $data['entries'] ?? []);

        return response()->json(['data' => ['rows' => $rows, 'expected_version' => (int) $contract->schedules()->max('version'), 'policy' => ScheduleService::POLICY,
            'principal_total' => Money::decimal(array_sum(array_map(fn ($r) => $r['kind'] === 'security_deposit' ? 0 : Money::cents($r['amount']), $rows))),
            'deposit_total' => Money::decimal(array_sum(array_map(fn ($r) => $r['kind'] === 'security_deposit' ? Money::cents($r['amount']) : 0, $rows))),
        ]]);
    }

    public function store(ScheduleRequest $request, Contract $contract, ScheduleService $service)
    {
        $schedule = $service->replace($contract, $request->validated(), $request->user()->id);

        return response()->json(['data' => ['id' => $schedule->id, 'version' => $schedule->version, 'mode' => $schedule->mode]]);
    }
}
