<?php

namespace App\Http\Controllers;

use App\Models\Expense;
use App\Services\ExpenseService;
use App\Support\Money;
use Illuminate\Http\Request;

class ExpenseController extends Controller
{
    public function index(Request $request)
    {
        $filters = $request->validate([
            'search' => 'nullable|string|max:150',
            'category' => 'nullable|string|max:40',
            'status' => 'nullable|in:all,open,unpaid,partial,paid',
            'land_id' => 'nullable|integer|exists:lands,id',
            'building_id' => 'nullable|integer|exists:buildings,id',
            'date_from' => 'nullable|date',
            'date_to' => 'nullable|date|after_or_equal:date_from',
            'page' => 'nullable|integer|min:1',
        ]);
        $query = Expense::query()->with(['land:id,name', 'building:id,name', 'payments'])->orderByDesc('occurred_on')->orderByDesc('id');
        if ($search = $filters['search'] ?? null) {
            $query->where('description', 'like', '%'.$search.'%');
        }
        if (! empty($filters['category'])) {
            $query->where('category', $filters['category']);
        }
        $status = $filters['status'] ?? 'all';
        if ($status === 'open') {
            $query->whereIn('status', ['unpaid', 'partial']);
        } elseif ($status !== 'all') {
            $query->where('status', $status);
        }
        if (! empty($filters['land_id'])) {
            $query->where('land_id', $filters['land_id']);
        }
        if (! empty($filters['building_id'])) {
            $query->where('building_id', $filters['building_id']);
        }
        if (! empty($filters['date_from'])) {
            $query->whereDate('occurred_on', '>=', $filters['date_from']);
        }
        if (! empty($filters['date_to'])) {
            $query->whereDate('occurred_on', '<=', $filters['date_to']);
        }
        $page = $query->paginate(20)->withQueryString();
        $page->getCollection()->transform(fn (Expense $e) => $this->payload($e));

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

    public function store(Request $request, ExpenseService $service)
    {
        $input = $request->validate([
            'category' => 'required|in:materials,labor,permits,finishing,maintenance,other',
            'description' => 'required|string|max:500',
            'amount' => 'required|numeric|gt:0|max:999999999999.99',
            'occurred_on' => 'required|date',
            'land_id' => 'nullable|integer|exists:lands,id',
            'building_id' => 'nullable|integer|exists:buildings,id',
            'notes' => 'nullable|string|max:5000',
            'pay_now' => 'nullable|numeric|min:0|max:999999999999.99',
            'paid_on' => 'nullable|date',
            'payment_notes' => 'nullable|string|max:5000',
        ]);
        $expense = $service->create($input, $request->user()->id);

        return response()->json(['message' => 'تم تسجيل المصروف.', 'data' => $this->payload($expense->load(['land:id,name', 'building:id,name', 'payments']))], 201);
    }

    public function show(Expense $expense)
    {
        return response()->json(['data' => $this->payload($expense->load(['land:id,name', 'building:id,name', 'payments']))]);
    }

    public function pay(Request $request, Expense $expense, ExpenseService $service)
    {
        $input = $request->validate([
            'amount' => 'required|numeric|gt:0|max:999999999999.99',
            'paid_on' => 'required|date',
            'notes' => 'nullable|string|max:5000',
        ]);
        $payment = $service->recordPayment($expense, $input, $request->user()->id);

        return response()->json(['message' => 'تم تسجيل دفعة المصروف.', 'data' => $payment], 201);
    }

    private function payload(Expense $expense): array
    {
        $paid = Money::cents($expense->payments->sum('amount'));

        return [
            'id' => $expense->id,
            'category' => $expense->category,
            'description' => $expense->description,
            'amount' => $expense->amount,
            'paid' => Money::decimal($paid),
            'remaining' => Money::decimal(max(0, Money::cents($expense->amount) - $paid)),
            'occurred_on' => $expense->occurred_on?->toDateString(),
            'status' => $expense->status,
            'land_id' => $expense->land_id,
            'building_id' => $expense->building_id,
            'land' => $expense->land,
            'building' => $expense->building,
            'notes' => $expense->notes,
            'payments' => $expense->payments,
            'created_at' => $expense->created_at?->toIso8601String(),
        ];
    }
}
