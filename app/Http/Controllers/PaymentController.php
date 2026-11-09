<?php

namespace App\Http\Controllers;

use App\Http\Requests\PaymentRequest;
use App\Http\Resources\PaymentResource;
use App\Models\Contract;
use App\Models\Customer;
use App\Models\Payment;
use App\Services\CollectionSummaryService;
use App\Services\PaymentService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class PaymentController extends Controller
{
    private function detail(Payment $payment)
    {
        return new PaymentResource($payment->load(['contract.customer', 'contract.unit.building', 'allocations.due', 'events', 'replacement']));
    }

    public function context(Request $request, Contract $contract, PaymentService $service)
    {
        $data = $request->validate(['corrects_payment_id' => 'nullable|integer|exists:payments,id']);
        $source = isset($data['corrects_payment_id']) ? Payment::findOrFail($data['corrects_payment_id']) : null;

        return response()->json(['data' => $service->context($contract, $source)]);
    }

    public function preview(PaymentRequest $request, PaymentService $service)
    {
        return response()->json(['data' => $service->preview($request->validated())]);
    }

    public function store(PaymentRequest $request, PaymentService $service)
    {
        $payment = $service->record($request->validated(), $request->user()->id);

        return $this->detail($payment)->response()->setStatusCode($payment->wasRecentlyCreated ? 201 : 200);
    }

    public function show(Payment $payment)
    {
        return $this->detail($payment);
    }

    public function receipt(Payment $payment)
    {
        if (! $payment->receipt_snapshot) {
            throw ValidationException::withMessages(['receipt' => 'هذا السجل القديم لا يحتوي على نسخة إيصال محفوظة.']);
        }

        return $this->detail($payment);
    }

    public function reverse(Request $request, Payment $payment, PaymentService $service)
    {
        $data = $request->validate(['reason' => 'required|string|max:1000', 'request_key' => 'required|uuid']);

        return $this->detail($service->reverse($payment, $data, $request->user()->id));
    }

    public function correct(PaymentRequest $request, Payment $payment, PaymentService $service)
    {
        $replacement = $service->record($request->validated(), $request->user()->id, $payment);

        return $this->detail($replacement)->response()->setStatusCode($replacement->wasRecentlyCreated ? 201 : 200);
    }

    public function index(Request $request, CollectionSummaryService $collections)
    {
        $f = $request->validate(['search' => 'nullable|string|max:150', 'status' => 'nullable|in:all,posted,reversed', 'method' => 'nullable|in:cash,transfer,wallet,other',
            'contract_id' => 'nullable|integer|exists:contracts,id', 'customer_id' => 'nullable|integer|exists:customers,id', 'building_id' => 'nullable|integer|exists:buildings,id',
            'paid_from' => ['nullable', 'date_format:Y-m-d'], 'paid_to' => ['nullable', 'date_format:Y-m-d', Rule::when($request->filled('paid_from'), 'after_or_equal:paid_from')], 'page' => 'nullable|integer|min:1']);
        $q = Payment::with(['contract.customer', 'contract.unit.building', 'allocations.due', 'replacement']);
        if (isset($f['status']) && $f['status'] !== 'all') {
            $q->where('status', $f['status']);
        }
        foreach (['method', 'contract_id'] as $key) {
            if (isset($f[$key])) {
                $q->where($key, $f[$key]);
            }
        }
        if (isset($f['customer_id'])) {
            $q->whereHas('contract', fn ($q) => $q->where('customer_id', $f['customer_id']));
        }
        if (isset($f['building_id'])) {
            $q->whereHas('contract.unit', fn ($q) => $q->where('building_id', $f['building_id']));
        }
        if (isset($f['paid_from'])) {
            $q->whereDate('paid_on', '>=', $f['paid_from']);
        }if (isset($f['paid_to'])) {
            $q->whereDate('paid_on', '<=', $f['paid_to']);
        }
        if (isset($f['search']) && $f['search'] !== '') {
            $search = $f['search'];
            $phone = Customer::normalizePhone($search);
            $q->where(function ($q) use ($search, $phone) {
                $q->whereHas('contract.customer', fn ($q) => $q->where('name', 'like', '%'.$search.'%')->when($phone !== '', fn ($q) => $q->orWhere('phone', 'like', '%'.$phone.'%')))
                    ->orWhere('reference', 'like', '%'.$search.'%');
                if (preg_match('/^R-0*([0-9]+)$/i', $search, $m)) {
                    $q->orWhere('payments.id', (int) $m[1]);
                }
                if (preg_match('/^C-0*([0-9]+)$/i', $search, $m)) {
                    $q->orWhere('contract_id', (int) $m[1]);
                }
            });
        }
        $totals = $collections->summarize($q);

        return PaymentResource::collection($q->orderByDesc('paid_on')->orderByDesc('id')->paginate(20)->withQueryString())->additional(['summary' => $totals]);
    }
}
