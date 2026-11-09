<?php

namespace App\Http\Controllers;

use App\Models\Building;
use App\Models\Contract;
use App\Models\Due;
use App\Models\Unit;
use App\Services\CollectionSummaryService;
use App\Services\DueBalanceService;
use App\Services\ReportService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    public function __invoke(Request $request, ReportService $reports, CollectionSummaryService $collections, DueBalanceService $balances)
    {
        $f = $request->validate(['building_id' => 'nullable|integer|exists:buildings,id']);

        return DB::transaction(function () use ($f, $reports, $collections, $balances) {
            $buildings = Building::query();
            $units = Unit::query();
            $contracts = Contract::query();
            if (! empty($f['building_id'])) {
                $buildings->whereKey($f['building_id']);
                $units->where('building_id', $f['building_id']);
                $contracts->whereHas('unit', fn ($q) => $q->where('building_id', $f['building_id']));
            }
            $active = (clone $contracts)->where('status', 'active')->where(fn ($q) => $q->where('type', 'sale')->orWhereDate('effective_end_date', '>=', today()));
            $dues = $reports->dues($f);
            $open = (clone $dues)->whereRaw(Due::remainingSql().' > 0');
            $overdue = (clone $open)->whereDate('due_date', '<', today());
            $upcoming = (clone $open)->whereDate('due_date', '>=', today())->whereDate('due_date', '<=', today()->addDays(30));
            $period = ['date_from' => today()->startOfMonth()->toDateString(), 'date_to' => today()->toDateString()];
            $payments = $reports->payments(array_merge($f, $period));

            return response()->json(['data' => [
                'as_of' => today()->toDateString(), 'building_id' => $f['building_id'] ?? null, 'collection_period' => $period,
                'counts' => ['buildings' => (clone $buildings)->count(), 'active_buildings' => (clone $buildings)->where('is_active', true)->count(),
                    'units' => (clone $units)->count(), 'available_units' => (clone $units)->whereRaw(Unit::availabilitySql().' = ?', ['available'])->count(),
                    'rented_units' => (clone $units)->whereRaw(Unit::availabilitySql().' = ?', ['rented'])->count(), 'sold_units' => (clone $units)->whereRaw(Unit::availabilitySql().' = ?', ['sold'])->count(),
                    'active_contracts' => (clone $active)->count(), 'active_sales' => (clone $active)->where('type', 'sale')->count(), 'active_rentals' => (clone $active)->where('type', 'rental')->count(),
                    'overdue_dues' => (clone $overdue)->count(), 'upcoming_dues' => (clone $upcoming)->count()],
                'collections' => $collections->summarize($payments), 'balances' => $balances->summarize($dues),
                'overdue' => $reports->rows((clone $overdue)->orderBy('due_date')->orderBy('id')->limit(6)->get(), 'dues'),
                'upcoming' => $reports->rows((clone $upcoming)->orderBy('due_date')->orderBy('id')->limit(6)->get(), 'dues'),
                'recent_payments' => $reports->rows($reports->payments($f)->where('status', 'posted')->orderByDesc('paid_on')->orderByDesc('id')->limit(6)->get(), 'collections'),
            ]]);
        });
    }
}
