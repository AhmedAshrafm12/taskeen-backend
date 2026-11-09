<?php

namespace App\Http\Controllers;

use App\Http\Resources\ContractDueSummaryResource;
use App\Http\Resources\DueResource;
use App\Models\Contract;
use App\Models\Customer;
use App\Models\Due;
use App\Models\Unit;
use App\Services\DueBalanceService;
use App\Support\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class DueController extends Controller
{
    public function index(Request $request, DueBalanceService $balances)
    {
        $f = $this->filters($request);
        $q = $this->duesQuery($f);
        $summary = $balances->summarize($q);

        return DueResource::collection(
            $q->orderBy('due_date')->orderBy('id')->paginate(20)->withQueryString()
        )->additional(['summary' => $summary]);
    }

    public function byContract(Request $request, DueBalanceService $balances)
    {
        $f = $this->filters($request, contractStatus: true);
        $status = $f['status'] ?? 'open';
        $today = today()->toDateString();
        $remaining = Due::remainingSql();
        $openAmount = "CASE WHEN ({$remaining}) > 0 THEN ({$remaining}) ELSE 0 END";

        $ids = Due::query()
            ->where('is_void', false)
            ->tap(fn (Builder $q) => $this->applySharedFilters($q, $f))
            ->select('contract_id')
            ->groupBy('contract_id');

        if ($status === 'open') {
            $ids->havingRaw("SUM(CASE WHEN kind <> 'security_deposit' THEN {$openAmount} ELSE 0 END) > 0");
        } elseif ($status === 'overdue') {
            $ids->havingRaw("SUM(CASE WHEN kind <> 'security_deposit' AND due_date < ? THEN {$openAmount} ELSE 0 END) > 0", [$today]);
        } elseif ($status === 'settled') {
            $ids->havingRaw("SUM(CASE WHEN kind <> 'security_deposit' THEN {$openAmount} ELSE 0 END) <= 0")
                ->havingRaw('COUNT(*) > 0');
        }

        $page = max(1, (int) ($f['page'] ?? 1));
        $perPage = 20;
        $idPage = (clone $ids)
            ->orderBy('contract_id')
            ->forPage($page, $perPage)
            ->pluck('contract_id');
        $total = (clone $ids)->get()->count();

        $contracts = Contract::with(['customer', 'unit.building'])
            ->whereIn('id', $idPage)
            ->get()
            ->keyBy('id');

        $dues = Due::with('allocations.payment')
            ->where('is_void', false)
            ->whereIn('contract_id', $idPage)
            ->orderBy('due_date')
            ->orderBy('id')
            ->get()
            ->groupBy('contract_id');

        $rows = [];
        foreach ($idPage as $contractId) {
            $contract = $contracts->get($contractId);
            if (! $contract) {
                continue;
            }
            $group = $dues->get($contractId, collect());
            $principalScheduled = 0;
            $principalPaid = 0;
            $principalRemaining = 0;
            $principalOverdue = 0;
            $principalDueNow = 0;
            $depositRemaining = 0;
            $depositOverdue = 0;
            $openCount = 0;
            $overdueCount = 0;
            $nextDue = null;

            foreach ($group as $due) {
                $paid = $due->paidCents();
                $left = max(0, Money::cents($due->amount) - $paid);
                $date = $due->due_date->toDateString();
                $isDeposit = $due->kind === 'security_deposit';

                if ($isDeposit) {
                    $depositRemaining += $left;
                    if ($left > 0 && $date < $today) {
                        $depositOverdue += $left;
                    }
                    continue;
                }

                $principalScheduled += Money::cents($due->amount);
                $principalPaid += $paid;
                $principalRemaining += $left;
                if ($left > 0) {
                    $openCount++;
                    $principalDueNow += $date <= $today ? $left : 0;
                    if ($date < $today) {
                        $principalOverdue += $left;
                        $overdueCount++;
                    }
                    if ($nextDue === null || $date < $nextDue) {
                        $nextDue = $date;
                    }
                }
            }

            $rows[] = [
                'contract_id' => $contract->id,
                'contract_number' => $contract->number,
                'type' => $contract->type,
                'status' => $contract->status,
                'customer' => $contract->customer->only('id', 'name', 'phone'),
                'unit' => [
                    'id' => $contract->unit->id,
                    'number' => $contract->unit->number,
                    'building_name' => $contract->unit->building->name,
                ],
                'principal_scheduled' => $principalScheduled,
                'principal_paid' => $principalPaid,
                'principal_remaining' => $principalRemaining,
                'principal_overdue' => $principalOverdue,
                'principal_due_now' => $principalDueNow,
                'deposit_remaining' => $depositRemaining,
                'deposit_overdue' => $depositOverdue,
                'open_dues_count' => $openCount,
                'overdue_dues_count' => $overdueCount,
                'next_due_date' => $nextDue,
                'progress_percent' => $principalScheduled > 0
                    ? (int) round(($principalPaid / $principalScheduled) * 100)
                    : 0,
            ];
        }

        $summary = $balances->summarize(
            Due::query()
                ->where('is_void', false)
                ->tap(fn (Builder $q) => $this->applySharedFilters($q, $f))
                ->whereIn('contract_id', (clone $ids)->select('contract_id'))
        );
        $lastPage = max(1, (int) ceil($total / $perPage));

        return ContractDueSummaryResource::collection($rows)->additional([
            'summary' => $summary,
            'meta' => [
                'current_page' => $page,
                'last_page' => $lastPage,
                'total' => $total,
                'per_page' => $perPage,
            ],
        ]);
    }

    private function filters(Request $request, bool $contractStatus = false): array
    {
        $statuses = $contractStatus
            ? 'all,open,overdue,settled'
            : 'all,open,upcoming,overdue,partial,paid,today,voided';

        return $request->validate([
            'search' => 'nullable|string|max:150',
            'contract_id' => 'nullable|integer|exists:contracts,id',
            'customer_id' => 'nullable|integer|exists:customers,id',
            'building_id' => 'nullable|integer|exists:buildings,id',
            'kind' => 'nullable|in:sale_cash,sale_installment,down_payment,rent,security_deposit',
            'status' => 'nullable|in:'.$statuses,
            'due_from' => ['nullable', 'date_format:Y-m-d'],
            'due_to' => ['nullable', 'date_format:Y-m-d', Rule::when($request->filled('due_from'), 'after_or_equal:due_from')],
            'page' => 'nullable|integer|min:1',
        ]);
    }

    private function duesQuery(array $f): Builder
    {
        $q = Due::with(['allocations.payment', 'contract.customer', 'contract.unit.building']);
        $this->applySharedFilters($q, $f);

        $status = $f['status'] ?? 'all';
        $q->where('is_void', $status === 'voided');
        $remaining = Due::remainingSql();
        $paid = Due::paidSql();
        if (in_array($status, ['open', 'upcoming', 'overdue', 'partial', 'today'])) {
            $q->whereRaw($remaining.' > 0');
        }
        if ($status === 'paid') {
            $q->whereRaw($remaining.' <= 0');
        }
        if ($status === 'partial') {
            $q->whereRaw($paid.' > 0');
        }
        if ($status === 'upcoming') {
            $q->whereDate('due_date', '>', today());
        }
        if ($status === 'overdue') {
            $q->whereDate('due_date', '<', today());
        }
        if ($status === 'today') {
            $q->whereDate('due_date', today());
        }

        return $q;
    }

    private function applySharedFilters(Builder $q, array $f): void
    {
        if (isset($f['contract_id'])) {
            $q->where('contract_id', $f['contract_id']);
        }
        if (isset($f['customer_id'])) {
            $q->whereHas('contract', fn ($q) => $q->where('customer_id', $f['customer_id']));
        }
        if (isset($f['building_id'])) {
            $q->whereHas('contract.unit', fn ($q) => $q->where('building_id', $f['building_id']));
        }
        if (isset($f['kind'])) {
            $q->where('kind', $f['kind']);
        }
        if (isset($f['due_from'])) {
            $q->whereDate('due_date', '>=', $f['due_from']);
        }
        if (isset($f['due_to'])) {
            $q->whereDate('due_date', '<=', $f['due_to']);
        }
        if (isset($f['search']) && $f['search'] !== '') {
            $search = $f['search'];
            $phone = Customer::normalizePhone($search);
            $number = Unit::normalizeNumber($search);
            $q->whereHas('contract', function ($q) use ($search, $phone, $number) {
                $q->whereHas('customer', fn ($q) => $q->where('name', 'like', '%'.$search.'%')->when($phone !== '', fn ($q) => $q->orWhere('phone', 'like', '%'.$phone.'%')))
                    ->orWhereHas('unit', fn ($q) => $q->where('number', 'like', '%'.$number.'%'));
                if (preg_match('/^(?:C-)?0*([0-9]+)$/i', $number, $match)) {
                    $q->orWhere('contracts.id', (int) $match[1]);
                }
            });
        }
    }
}
