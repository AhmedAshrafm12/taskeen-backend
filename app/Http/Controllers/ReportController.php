<?php

namespace App\Http\Controllers;

use App\Http\Requests\ReportRequest;
use App\Services\ReportService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ReportController extends Controller
{
    public function index(ReportRequest $request, ReportService $service)
    {
        return DB::transaction(function () use ($request, $service) {
            $f = $request->validated();
            $page = $service->details($f)->paginate(20)->withQueryString();

            return response()->json(['data' => $service->rows($page->getCollection(), $f['type']),
                'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total()],
                'report' => $service->metadata($f)]);
        });
    }

    public function print(ReportRequest $request, ReportService $service)
    {
        return DB::transaction(function () use ($request, $service) {
            $f = $request->validated();
            $q = $service->details($f);
            $count = (clone $q)->count();
            if ($count > 5000) {
                throw ValidationException::withMessages(['print' => 'التقرير يتجاوز ٥٠٠٠ صف. اختر فترة أقصر أو مبنى محدد للطباعة دون اقتطاع النتائج.']);
            }

            return response()->json(['data' => $service->rows($q->get(), $f['type']), 'total' => $count, 'report' => $service->metadata($f)]);
        });
    }
}
