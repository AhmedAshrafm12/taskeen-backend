<?php

namespace App\Http\Controllers;

use App\Http\Requests\CustomerRequest;
use App\Http\Resources\CustomerResource;
use App\Models\Customer;
use App\Models\Due;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CustomerController extends Controller
{
    public function index(Request $request)
    {
        $input = $request->validate([
            'search' => 'nullable|string|max:150',
            'page' => 'nullable|integer|min:1',
            'balance' => 'nullable|in:all,overdue,open,settled',
            'contract_type' => 'nullable|in:all,sale,rental',
            'activity' => 'nullable|in:all,active,inactive',
        ]);
        $query = $this->filtered($input);
        $this->withListMetrics($query);

        $page = $query->orderByDesc('id')->paginate(20)->withQueryString();

        return CustomerResource::collection($page)->additional(['summary' => $this->summaryFor($input)]);
    }

    public function store(CustomerRequest $request)
    {
        return $this->save($request);
    }

    public function show(Customer $customer)
    {
        $query = Customer::query()->whereKey($customer->id);
        $this->withListMetrics($query);

        return new CustomerResource($query->firstOrFail());
    }

    public function update(CustomerRequest $request, Customer $customer)
    {
        return $this->save($request, $customer);
    }

    private function filtered(array $input)
    {
        $query = Customer::query();
        if (isset($input['search']) && $input['search'] !== '') {
            $search = $input['search'];
            $phone = Customer::normalizePhone($search);
            $query->where(fn ($q) => $q->where('name', 'like', '%'.$search.'%')
                ->when($phone !== '', fn ($q) => $q->orWhere('phone', 'like', '%'.$phone.'%')));
        }

        $activity = $input['activity'] ?? 'all';
        if ($activity === 'active') {
            $query->whereHas('contracts', fn ($q) => $q->where('status', 'active'));
        } elseif ($activity === 'inactive') {
            $query->whereDoesntHave('contracts', fn ($q) => $q->where('status', 'active'));
        }

        $type = $input['contract_type'] ?? 'all';
        if (in_array($type, ['sale', 'rental'], true)) {
            $query->whereHas('contracts', fn ($q) => $q->where('type', $type)->where('status', '!=', 'cancelled'));
        }

        $balance = $input['balance'] ?? 'all';
        if (in_array($balance, ['overdue', 'open', 'settled'], true)) {
            $this->applyBalanceFilter($query, $balance);
        }

        return $query;
    }

    private function applyBalanceFilter($query, string $balance): void
    {
        $remaining = Due::remainingSql();
        $openAmount = "CASE WHEN ({$remaining}) > 0 THEN ({$remaining}) ELSE 0 END";
        $today = today()->toDateString();
        $customerIds = DB::table('dues')
            ->join('contracts', 'contracts.id', '=', 'dues.contract_id')
            ->where('dues.is_void', false)
            ->groupBy('contracts.customer_id')
            ->select('contracts.customer_id');

        if ($balance === 'overdue') {
            $query->whereIn('id', (clone $customerIds)->havingRaw(
                "SUM(CASE WHEN dues.kind <> 'security_deposit' AND dues.due_date < ? THEN {$openAmount} ELSE 0 END) > 0",
                [$today]
            ));
        } elseif ($balance === 'open') {
            $query->whereIn('id', (clone $customerIds)->havingRaw(
                "SUM(CASE WHEN dues.kind <> 'security_deposit' THEN {$openAmount} ELSE 0 END) > 0"
            ));
        } else {
            $query->whereNotIn('id', (clone $customerIds)->havingRaw(
                "SUM(CASE WHEN dues.kind <> 'security_deposit' THEN {$openAmount} ELSE 0 END) > 0"
            ));
        }
    }

    private function withListMetrics($query): void
    {
        $remaining = Due::remainingSql();
        $openAmount = "CASE WHEN ({$remaining}) > 0 THEN ({$remaining}) ELSE 0 END";
        $today = today()->toDateString();

        $query->withCount([
            'contracts',
            'contracts as active_contracts_count' => fn ($q) => $q->where('status', 'active'),
        ])->selectSub(
            DB::table('dues')
                ->join('contracts', 'contracts.id', '=', 'dues.contract_id')
                ->whereColumn('contracts.customer_id', 'customers.id')
                ->where('dues.is_void', false)
                ->selectRaw("COALESCE(SUM(CASE WHEN dues.kind <> 'security_deposit' THEN {$openAmount} ELSE 0 END), 0)"),
            'principal_remaining'
        )->selectSub(
            DB::table('dues')
                ->join('contracts', 'contracts.id', '=', 'dues.contract_id')
                ->whereColumn('contracts.customer_id', 'customers.id')
                ->where('dues.is_void', false)
                ->selectRaw("COALESCE(SUM(CASE WHEN dues.kind <> 'security_deposit' AND dues.due_date < ? THEN {$openAmount} ELSE 0 END), 0)", [$today]),
            'principal_overdue'
        )->selectSub(
            DB::table('payments')
                ->join('contracts', 'contracts.id', '=', 'payments.contract_id')
                ->whereColumn('contracts.customer_id', 'customers.id')
                ->where('payments.status', 'posted')
                ->selectRaw('MAX(payments.paid_on)'),
            'last_payment_on'
        );
    }

    private function summaryFor(array $input): array
    {
        $base = $this->filtered($input);
        $ids = (clone $base)->select('customers.id');
        $total = (clone $base)->count();
        $active = (clone $base)->whereHas('contracts', fn ($q) => $q->where('status', 'active'))->count();

        $remaining = Due::remainingSql();
        $openAmount = "CASE WHEN ({$remaining}) > 0 THEN ({$remaining}) ELSE 0 END";
        $today = today()->toDateString();
        $money = DB::table('dues')
            ->join('contracts', 'contracts.id', '=', 'dues.contract_id')
            ->whereIn('contracts.customer_id', $ids)
            ->where('dues.is_void', false)
            ->selectRaw("COALESCE(SUM(CASE WHEN dues.kind <> 'security_deposit' THEN {$openAmount} ELSE 0 END), 0) as remaining")
            ->selectRaw("COALESCE(SUM(CASE WHEN dues.kind <> 'security_deposit' AND dues.due_date < ? THEN {$openAmount} ELSE 0 END), 0) as overdue", [$today])
            ->first();

        return [
            'customers' => $total,
            'active_customers' => $active,
            'principal_remaining' => Money::decimal(Money::cents($money->remaining ?? 0)),
            'principal_overdue' => Money::decimal(Money::cents($money->overdue ?? 0)),
        ];
    }

    private function save(CustomerRequest $request, ?Customer $customer = null)
    {
        return DB::transaction(function () use ($request, $customer) {
            if ($customer) {
                $customer = Customer::lockForUpdate()->findOrFail($customer->id);
            }
            $input = $request->validated();
            $matches = Customer::where('phone', $input['phone'])->when($customer, fn ($q) => $q->whereKeyNot($customer->id));
            // Editing an unchanged phone does not need another acknowledgement.
            if ((! $customer || $customer->phone !== $input['phone']) && ! $request->boolean('allow_duplicate_phone') && $matches->exists()) {
                return response()->json([
                    'message' => 'رقم الهاتف مسجل لعميل آخر. راجع العملاء الموجودين قبل حفظ البيانات.',
                    'duplicates' => $matches->orderBy('id')->limit(5)->get(['id', 'name', 'phone']),
                ], 409);
            }
            unset($input['allow_duplicate_phone']);
            $created = ! $customer;
            if ($customer) {
                $customer->update($input);
            } else {
                $customer = Customer::create($input);
            }
            logger()->info($created ? 'Customer created' : 'Customer updated', [
                'customer_id' => $customer->id, 'user_id' => $request->user()->id,
                'fields' => array_keys($input),
            ]);

            $query = Customer::query()->whereKey($customer->id);
            $this->withListMetrics($query);

            return (new CustomerResource($query->firstOrFail()))->response()->setStatusCode($created ? 201 : 200);
        });
    }
}
