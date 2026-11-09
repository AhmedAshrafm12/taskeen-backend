<?php

namespace App\Http\Controllers;

use App\Http\Requests\ContractRequest;
use App\Http\Resources\ContractResource;
use App\Models\Contract;
use App\Models\Unit;
use App\Services\ContractService;
use Illuminate\Http\Request;

class ContractController extends Controller
{
    public function index(Request $request)
    {
        $filters = $request->validate(['search' => 'nullable|string|max:150', 'type' => 'nullable|in:sale,rental', 'status' => 'nullable|in:draft,active,completed,ended,cancelled', 'unit_id' => 'nullable|integer|exists:units,id', 'customer_id' => 'nullable|integer|exists:customers,id', 'building_id' => 'nullable|integer|exists:buildings,id', 'page' => 'nullable|integer|min:1']);
        $query = Contract::with(['unit.building', 'customer']);
        foreach (['type', 'unit_id', 'customer_id'] as $key) {
            if (isset($filters[$key])) {
                $query->where($key, $filters[$key]);
            }
        }
        if (isset($filters['building_id'])) {
            $query->whereHas('unit', fn ($q) => $q->where('building_id', $filters['building_id']));
        }
        if (isset($filters['status'])) {
            $status = $filters['status'];
            if ($status === 'ended') {
                $query->where(fn ($q) => $q->where('status', 'ended')->orWhere(fn ($q) => $q->where('status', 'active')->where('type', 'rental')->whereDate('effective_end_date', '<', today())));
            } elseif ($status === 'active') {
                $query->where('status', 'active')->where(fn ($q) => $q->where('type', 'sale')->orWhereDate('effective_end_date', '>=', today()));
            } else {
                $query->where('status', $status);
            }
        }
        if ($search = $filters['search'] ?? null) {
            $identifier = Unit::normalizeNumber($search);
            $query->where(function ($q) use ($search, $identifier) {
                $q->whereHas('customer', fn ($q) => $q->where('name', 'like', '%'.$search.'%'))
                    ->orWhereHas('unit', fn ($q) => $q->where('number', 'like', '%'.$identifier.'%'));
                if (preg_match('/^(?:C-)?0*([0-9]+)$/i', $identifier, $match)) {
                    $q->orWhere('contracts.id', (int) $match[1]);
                }
            });
        }

        return ContractResource::collection($query->latest('id')->paginate(20)->withQueryString());
    }

    public function preview(ContractRequest $request, ContractService $service)
    {
        return response()->json(['data' => $service->preview($request->validated())]);
    }

    public function store(ContractRequest $request, ContractService $service)
    {
        $contract = $service->create($request->validated(), $request->user()->id);

        return (new ContractResource($contract->load(['unit.building', 'customer', 'events'])))->response()->setStatusCode($contract->wasRecentlyCreated ? 201 : 200);
    }

    public function show(Contract $contract)
    {
        return new ContractResource($contract->load(['unit.building', 'customer', 'events']));
    }

    public function transition(Request $request, Contract $contract, ContractService $service)
    {
        $input = $request->validate([
            'action' => 'required|in:activate,cancel,terminate,complete',
            'reason' => 'required|string|max:1000',
            'effective_date' => 'required_if:action,terminate|prohibited_unless:action,terminate|nullable|date_format:Y-m-d',
            'settlement_mode' => 'prohibited_unless:action,terminate|nullable|in:keep,waive,partial',
            'waive_amount' => 'required_if:settlement_mode,partial|prohibited_unless:settlement_mode,partial|nullable|regex:/^\d+(\.\d{1,2})?$/',
            'request_key' => 'required|uuid',
        ]);
        if (($input['action'] ?? null) === 'terminate') {
            $input['settlement_mode'] = $input['settlement_mode'] ?? 'keep';
        }

        return new ContractResource($service->transition($contract, $input, $request->user()->id)->load(['unit.building', 'customer', 'events']));
    }
}
