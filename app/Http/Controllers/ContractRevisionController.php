<?php

namespace App\Http\Controllers;

use App\Http\Resources\ContractResource;
use App\Models\Contract;
use App\Services\ContractRevisionService;
use Illuminate\Http\Request;

class ContractRevisionController extends Controller
{
    public function preview(Request $request, Contract $contract, ContractRevisionService $service)
    {
        return response()->json(['data' => $service->preview($contract, $this->payload($request, false))]);
    }

    public function store(Request $request, Contract $contract, ContractRevisionService $service)
    {
        $revision = $service->commit($contract, $this->payload($request, true), $request->user()->id);

        return response()->json([
            'message' => 'تم حفظ تعديل العقد.',
            'data' => [
                'revision_id' => $revision->id,
                'contract' => (new ContractResource($contract->fresh()->load(['unit.building', 'customer', 'events'])))->resolve(),
            ],
        ]);
    }

    private function payload(Request $request, bool $commit): array
    {
        $rules = [
            'amount' => 'nullable|numeric|decimal:0,2|gt:0|max:999999999999.99',
            'start_date' => 'nullable|date_format:Y-m-d',
            'end_date' => 'nullable|date_format:Y-m-d',
            'down_payment' => 'nullable|numeric|decimal:0,2|min:0',
            'security_deposit' => 'nullable|numeric|decimal:0,2|min:0|max:999999999999.99',
            'frequency' => 'nullable|in:monthly,quarterly,yearly',
            'installments_count' => 'nullable|integer|min:1|max:600',
            'first_due_date' => 'nullable|date_format:Y-m-d',
            'notes' => 'nullable|string|max:5000',
            'date_scope' => 'required|in:contract_only,unpaid_only,all_dues',
            'amount_scope' => 'required|in:redistribute_unpaid,manual_dues,settlement_line',
            'dues' => 'required_if:amount_scope,manual_dues|array',
            'dues.*.id' => 'required_with:dues|integer',
            'dues.*.amount' => 'required_with:dues|numeric|decimal:0,2|gt:0',
            'dues.*.due_date' => 'nullable|date_format:Y-m-d',
            'surplus_action' => 'nullable|in:reallocate,credit,refund',
            'refund_confirmed' => 'nullable|boolean',
            'expected_schedule_version' => 'required|integer|min:1',
            'expected_principal_paid' => 'required|numeric|decimal:0,2|min:0',
        ];
        if ($commit) {
            $rules['reason'] = 'required|string|max:1000';
            $rules['request_key'] = 'required|uuid';
        } else {
            $rules['reason'] = 'nullable|string|max:1000';
        }

        return $request->validate($rules);
    }
}
