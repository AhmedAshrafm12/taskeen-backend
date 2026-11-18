<?php

namespace App\Http\Controllers;

use App\Models\Land;
use App\Models\LandPurchasePayment;
use App\Services\LandService;
use App\Support\Money;
use Illuminate\Http\Request;

class LandController extends Controller
{
    public function options()
    {
        return response()->json([
            'data' => Land::orderBy('name')->get(['id', 'name', 'status']),
        ]);
    }

    public function index(Request $request)
    {
        $filters = $request->validate([
            'search' => 'nullable|string|max:150',
            'status' => 'nullable|in:all,vacant,linked',
            'page' => 'nullable|integer|min:1',
        ]);
        $query = Land::query()->withCount('buildings')->orderByDesc('id');
        if ($search = $filters['search'] ?? null) {
            $query->where(fn ($q) => $q->where('name', 'like', '%'.$search.'%')->orWhere('location', 'like', '%'.$search.'%'));
        }
        if (($filters['status'] ?? 'all') !== 'all') {
            $query->where('status', $filters['status']);
        }
        $page = $query->paginate(12)->withQueryString();
        $page->getCollection()->transform(fn (Land $land) => $this->payload($land));

        return response()->json([
            'data' => $page->items(),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'total' => $page->total(),
                'per_page' => $page->perPage(),
            ],
        ]);
    }

    public function store(Request $request, LandService $service)
    {
        $input = $this->validated($request);
        $land = $service->create($input);

        return response()->json(['message' => 'تم حفظ الأرض.', 'data' => $this->payload($land->fresh()->loadCount('buildings'))], 201);
    }

    public function show(Land $land)
    {
        $land->load(['buildings:id,land_id,name,address,is_active', 'purchasePayments', 'expenses.payments']);

        return response()->json(['data' => $this->detailPayload($land)]);
    }

    public function update(Request $request, Land $land, LandService $service)
    {
        $input = $this->validated($request);
        $land = $service->update($land, $input);

        return response()->json(['message' => 'تم تحديث الأرض.', 'data' => $this->payload($land->loadCount('buildings'))]);
    }

    public function purchasePayment(Request $request, Land $land, LandService $service)
    {
        $input = $request->validate([
            'amount' => 'required|numeric|gt:0|max:999999999999.99',
            'paid_on' => 'required|date',
            'notes' => 'nullable|string|max:5000',
        ]);
        $payment = $service->recordPurchasePayment($land, $input, $request->user()->id);

        return response()->json(['message' => 'تم تسجيل دفعة شراء الأرض.', 'data' => $payment], 201);
    }

    public function linkBuilding(Request $request, Land $land, LandService $service)
    {
        $input = $request->validate(['building_id' => 'required|integer|exists:buildings,id']);
        $building = $service->linkBuilding($land, (int) $input['building_id']);

        return response()->json(['message' => 'تم ربط المبنى بالأرض.', 'data' => $building]);
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'name' => 'required|string|max:150',
            'location' => 'required|string|max:500',
            'area' => 'nullable|numeric|min:0|max:999999999.99',
            'purchase_date' => 'nullable|date',
            'purchase_price' => 'nullable|numeric|min:0|max:999999999999.99',
            'status' => 'nullable|in:vacant,linked',
            'notes' => 'nullable|string|max:5000',
        ]);
    }

    private function payload(Land $land): array
    {
        $paid = Money::cents(LandPurchasePayment::where('land_id', $land->id)->sum('amount'));
        $price = Money::cents($land->purchase_price);
        $extra = 0;
        foreach ($land->relationLoaded('expenses') ? $land->expenses : $land->expenses()->with('payments')->get() as $expense) {
            $extra += Money::cents($expense->amount);
        }

        return [
            'id' => $land->id,
            'name' => $land->name,
            'location' => $land->location,
            'area' => $land->area,
            'purchase_date' => $land->purchase_date?->toDateString(),
            'purchase_price' => $land->purchase_price,
            'purchase_paid' => Money::decimal($paid),
            'purchase_remaining' => Money::decimal(max(0, $price - $paid)),
            'extra_costs' => Money::decimal($extra),
            'status' => $land->status === 'building' ? 'linked' : $land->status,
            'notes' => $land->notes,
            'buildings_count' => (int) ($land->buildings_count ?? $land->buildings()->count()),
            'created_at' => $land->created_at?->toIso8601String(),
        ];
    }

    private function detailPayload(Land $land): array
    {
        $base = $this->payload($land);
        $base['buildings'] = $land->buildings;
        $base['purchase_payments'] = $land->purchasePayments;
        $expenseRows = [];
        foreach ($land->expenses as $expense) {
            $paid = Money::cents($expense->payments->sum('amount'));
            $expenseRows[] = [
                'id' => $expense->id,
                'category' => $expense->category,
                'description' => $expense->description,
                'amount' => $expense->amount,
                'paid' => Money::decimal($paid),
                'remaining' => Money::decimal(max(0, Money::cents($expense->amount) - $paid)),
                'status' => $expense->status,
                'occurred_on' => $expense->occurred_on?->toDateString(),
            ];
        }
        $base['expenses'] = $expenseRows;

        return $base;
    }
}
