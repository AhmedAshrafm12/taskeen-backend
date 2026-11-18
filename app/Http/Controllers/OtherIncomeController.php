<?php

namespace App\Http\Controllers;

use App\Models\OtherIncome;
use App\Services\OtherIncomeService;
use Illuminate\Http\Request;

class OtherIncomeController extends Controller
{
    public function index(Request $request)
    {
        $filters = $request->validate([
            'search' => 'nullable|string|max:150',
            'date_from' => 'nullable|date',
            'date_to' => 'nullable|date|after_or_equal:date_from',
            'page' => 'nullable|integer|min:1',
        ]);
        $query = OtherIncome::query()->with(['land:id,name', 'building:id,name'])->orderByDesc('occurred_on')->orderByDesc('id');
        if ($search = $filters['search'] ?? null) {
            $query->where('description', 'like', '%'.$search.'%');
        }
        if (! empty($filters['date_from'])) {
            $query->whereDate('occurred_on', '>=', $filters['date_from']);
        }
        if (! empty($filters['date_to'])) {
            $query->whereDate('occurred_on', '<=', $filters['date_to']);
        }

        $page = $query->paginate(20)->withQueryString();

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

    public function store(Request $request, OtherIncomeService $service)
    {
        $input = $request->validate([
            'category' => 'nullable|string|max:40',
            'description' => 'required|string|max:500',
            'amount' => 'required|numeric|gt:0|max:999999999999.99',
            'occurred_on' => 'required|date',
            'land_id' => 'nullable|integer|exists:lands,id',
            'building_id' => 'nullable|integer|exists:buildings,id',
            'notes' => 'nullable|string|max:5000',
        ]);
        $row = $service->create($input, $request->user()->id);

        return response()->json(['message' => 'تم تسجيل الإيراد.', 'data' => $row], 201);
    }
}
