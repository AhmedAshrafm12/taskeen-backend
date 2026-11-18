<?php

namespace App\Http\Controllers;

use App\Models\CapitalTransaction;
use App\Models\CashTransaction;
use App\Models\Expense;
use App\Models\Land;
use App\Models\LandPurchasePayment;
use App\Services\CapitalService;
use App\Services\CashLedgerService;
use App\Support\Money;
use Illuminate\Http\Request;

class FinanceController extends Controller
{
    public function summary(Request $request, CashLedgerService $cash, CapitalService $capital)
    {
        $filters = $request->validate([
            'date_from' => 'nullable|date',
            'date_to' => 'nullable|date|after_or_equal:date_from',
        ]);
        $period = $cash->periodTotals($filters['date_from'] ?? null, $filters['date_to'] ?? null);
        $unpaidExpenses = Expense::query()->whereIn('status', ['unpaid', 'partial'])->get(['id', 'amount']);
        $unpaidCents = 0;
        foreach ($unpaidExpenses as $expense) {
            $paid = Money::cents($expense->payments()->sum('amount'));
            $unpaidCents += max(0, Money::cents($expense->amount) - $paid);
        }
        $landUnpaid = 0;
        foreach (Land::query()->get(['id', 'purchase_price']) as $land) {
            $paid = Money::cents(LandPurchasePayment::where('land_id', $land->id)->sum('amount'));
            $landUnpaid += max(0, Money::cents($land->purchase_price) - $paid);
        }

        return response()->json([
            'data' => [
                'cash_balance' => $cash->balance(),
                'has_opening_balance' => $capital->hasOpeningBalance(),
                'period' => [
                    'date_from' => $filters['date_from'] ?? null,
                    'date_to' => $filters['date_to'] ?? null,
                    'inflow' => $period['inflow'],
                    'outflow' => $period['outflow'],
                ],
                'unpaid_costs' => Money::decimal($unpaidCents + $landUnpaid),
                'unpaid_expenses' => Money::decimal($unpaidCents),
                'unpaid_land_purchases' => Money::decimal($landUnpaid),
            ],
        ]);
    }

    public function cashTransactions(Request $request)
    {
        $filters = $request->validate([
            'page' => 'nullable|integer|min:1',
            'date_from' => 'nullable|date',
            'date_to' => 'nullable|date|after_or_equal:date_from',
            'direction' => 'nullable|in:all,in,out',
            'source_type' => 'nullable|string|max:40',
        ]);
        $query = CashTransaction::query()->orderByDesc('occurred_on')->orderByDesc('id');
        if (! empty($filters['date_from'])) {
            $query->whereDate('occurred_on', '>=', $filters['date_from']);
        }
        if (! empty($filters['date_to'])) {
            $query->whereDate('occurred_on', '<=', $filters['date_to']);
        }
        if (($filters['direction'] ?? 'all') !== 'all') {
            $query->where('direction', $filters['direction']);
        }
        if (! empty($filters['source_type'])) {
            $query->where('source_type', $filters['source_type']);
        }

        $page = $query->paginate(20)->withQueryString();
        $rows = collect($page->items())->map(fn (CashTransaction $row) => [
            'id' => $row->id,
            'direction' => $row->direction,
            'signed_amount' => $row->signed_amount,
            'occurred_on' => $row->occurred_on?->toDateString(),
            'source_type' => $row->source_type,
            'source_id' => $row->source_id,
            'label' => $row->label,
            'notes' => $row->notes,
        ])->all();

        return response()->json([
            'data' => $rows,
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'total' => $page->total(),
                'per_page' => $page->perPage(),
            ],
        ]);
    }

    public function openingBalance(Request $request, CapitalService $capital)
    {
        $input = $request->validate([
            'amount' => 'required|numeric|min:0|max:999999999999.99',
            'occurred_on' => 'required|date',
            'reference' => 'nullable|string|max:255',
            'notes' => 'nullable|string|max:5000',
        ]);
        $row = $capital->opening($input, $request->user()->id);

        return response()->json(['message' => 'تم تسجيل الرصيد الافتتاحي.', 'data' => $row], 201);
    }

    public function capitalAddition(Request $request, CapitalService $capital)
    {
        $input = $request->validate([
            'amount' => 'required|numeric|gt:0|max:999999999999.99',
            'occurred_on' => 'required|date',
            'reference' => 'nullable|string|max:255',
            'notes' => 'nullable|string|max:5000',
        ]);
        $row = $capital->add($input, $request->user()->id);

        return response()->json(['message' => 'تمت إضافة رأس المال.', 'data' => $row], 201);
    }

    public function ownerWithdrawal(Request $request, CapitalService $capital)
    {
        $input = $request->validate([
            'amount' => 'required|numeric|gt:0|max:999999999999.99',
            'occurred_on' => 'required|date',
            'reference' => 'nullable|string|max:255',
            'notes' => 'nullable|string|max:5000',
        ]);
        $row = $capital->withdraw($input, $request->user()->id);

        return response()->json(['message' => 'تم تسجيل سحب المالك.', 'data' => $row], 201);
    }

    public function capitalIndex(Request $request)
    {
        $request->validate(['page' => 'nullable|integer|min:1']);
        $page = CapitalTransaction::query()->orderByDesc('occurred_on')->orderByDesc('id')->paginate(20)->withQueryString();
        $rows = collect($page->items())->map(fn (CapitalTransaction $row) => [
            'id' => $row->id,
            'type' => $row->type,
            'amount' => $row->amount,
            'occurred_on' => $row->occurred_on?->toDateString(),
            'reference' => $row->reference,
            'notes' => $row->notes,
        ])->all();

        return response()->json([
            'data' => $rows,
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'total' => $page->total(),
                'per_page' => $page->perPage(),
            ],
        ]);
    }
}
