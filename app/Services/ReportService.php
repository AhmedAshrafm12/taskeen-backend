<?php

namespace App\Services;

use App\Http\Resources\DueResource;
use App\Models\Building;
use App\Models\CapitalTransaction;
use App\Models\CashTransaction;
use App\Models\Contract;
use App\Models\Due;
use App\Models\Expense;
use App\Models\ExpensePayment;
use App\Models\Land;
use App\Models\LandPurchasePayment;
use App\Models\OtherIncome;
use App\Models\Payment;
use App\Support\Money;
use Illuminate\Database\Eloquent\Builder;

class ReportService
{
    public function payments(array $f)
    {
        $q = Payment::query()->select(['id', 'contract_id', 'amount', 'paid_on', 'method', 'status', 'reference'])->with(['contract.customer', 'contract.unit.building', 'allocations.due']);
        if (! empty($f['building_id'])) {
            $q->whereHas('contract.unit', fn ($q) => $q->where('building_id', $f['building_id']));
        }

        return $this->dates($q, $f, 'paid_on');
    }

    public function dues(array $f)
    {
        $q = Due::where('is_void', false)->with(['allocations.payment', 'contract.customer', 'contract.unit.building']);
        if (! empty($f['building_id'])) {
            $q->whereHas('contract.unit', fn ($q) => $q->where('building_id', $f['building_id']));
        }

        return $this->dates($q, $f, 'due_date');
    }

    public function contracts(array $f)
    {
        $q = Contract::with(['customer', 'unit.building']);
        if (! empty($f['building_id'])) {
            $q->whereHas('unit', fn ($q) => $q->where('building_id', $f['building_id']));
        }

        return $this->dates($q, $f, 'start_date');
    }

    public function cashMovements(array $f)
    {
        $q = CashTransaction::query();
        $this->dates($q, $f, 'occurred_on');
        $this->scopeCashToBuilding($q, $f['building_id'] ?? null);
        $this->scopeCashToLand($q, $f['land_id'] ?? null);

        return $q;
    }

    private function dates($q, array $f, string $column)
    {
        if (! empty($f['date_from'])) {
            $q->whereDate($column, '>=', $f['date_from']);
        }
        if (! empty($f['date_to'])) {
            $q->whereDate($column, '<=', $f['date_to']);
        }

        return $q;
    }

    private function scopeCashToBuilding(Builder $q, $buildingId): void
    {
        if (empty($buildingId)) {
            return;
        }
        $buildingId = (int) $buildingId;
        $q->where(function (Builder $outer) use ($buildingId) {
            $outer->where(function (Builder $q) use ($buildingId) {
                $q->whereIn('source_type', ['payment', 'payment_reversal'])
                    ->whereIn('source_id', function ($sub) use ($buildingId) {
                        $sub->select('payments.id')
                            ->from('payments')
                            ->join('contracts', 'contracts.id', '=', 'payments.contract_id')
                            ->join('units', 'units.id', '=', 'contracts.unit_id')
                            ->where('units.building_id', $buildingId);
                    });
            })->orWhere(function (Builder $q) use ($buildingId) {
                $q->where('source_type', 'expense_payment')
                    ->whereIn('source_id', function ($sub) use ($buildingId) {
                        $sub->select('expense_payments.id')
                            ->from('expense_payments')
                            ->join('expenses', 'expenses.id', '=', 'expense_payments.expense_id')
                            ->where('expenses.building_id', $buildingId);
                    });
            })->orWhere(function (Builder $q) use ($buildingId) {
                $q->where('source_type', 'land_purchase_payment')
                    ->whereIn('source_id', function ($sub) use ($buildingId) {
                        $sub->select('land_purchase_payments.id')
                            ->from('land_purchase_payments')
                            ->join('buildings', 'buildings.land_id', '=', 'land_purchase_payments.land_id')
                            ->where('buildings.id', $buildingId);
                    });
            })->orWhere(function (Builder $q) use ($buildingId) {
                $q->where('source_type', 'other_income')
                    ->whereIn('source_id', function ($sub) use ($buildingId) {
                        $sub->select('other_incomes.id')
                            ->from('other_incomes')
                            ->where('other_incomes.building_id', $buildingId);
                    });
            });
        });
    }

    private function scopeCashToLand(Builder $q, $landId): void
    {
        if (empty($landId)) {
            return;
        }
        $landId = (int) $landId;
        $q->where(function (Builder $outer) use ($landId) {
            $outer->where(function (Builder $q) use ($landId) {
                $q->where('source_type', 'land_purchase_payment')
                    ->whereIn('source_id', function ($sub) use ($landId) {
                        $sub->select('id')->from('land_purchase_payments')->where('land_id', $landId);
                    });
            })->orWhere(function (Builder $q) use ($landId) {
                $q->where('source_type', 'expense_payment')
                    ->whereIn('source_id', function ($sub) use ($landId) {
                        $sub->select('expense_payments.id')
                            ->from('expense_payments')
                            ->join('expenses', 'expenses.id', '=', 'expense_payments.expense_id')
                            ->where(function ($e) use ($landId) {
                                $e->where('expenses.land_id', $landId)
                                    ->orWhereIn('expenses.building_id', function ($b) use ($landId) {
                                        $b->select('id')->from('buildings')->where('land_id', $landId);
                                    });
                            });
                    });
            })->orWhere(function (Builder $q) use ($landId) {
                $q->where('source_type', 'other_income')
                    ->whereIn('source_id', function ($sub) use ($landId) {
                        $sub->select('id')->from('other_incomes')->where('land_id', $landId);
                    });
            })->orWhere(function (Builder $q) use ($landId) {
                $q->whereIn('source_type', ['payment', 'payment_reversal'])
                    ->whereIn('source_id', function ($sub) use ($landId) {
                        $sub->select('payments.id')
                            ->from('payments')
                            ->join('contracts', 'contracts.id', '=', 'payments.contract_id')
                            ->join('units', 'units.id', '=', 'contracts.unit_id')
                            ->join('buildings', 'buildings.id', '=', 'units.building_id')
                            ->where('buildings.land_id', $landId);
                    });
            });
        });
    }

    public function details(array $f)
    {
        return match ($f['type']) {
            'collections' => $this->payments($f)->orderByDesc('paid_on')->orderByDesc('id'),
            'dues' => $this->dues($f)->orderBy('due_date')->orderBy('id'),
            'contracts' => $this->contracts($f)->orderByDesc('start_date')->orderByDesc('id'),
            'finance' => $this->cashMovements($f)->orderByDesc('occurred_on')->orderByDesc('id'),
        };
    }

    private function sumMoney($query): string
    {
        $sum = 0;
        (clone $query)->setEagerLoads([])->select('id', 'amount')->reorder()->chunkById(200, function ($rows) use (&$sum) {
            foreach ($rows as $row) {
                $sum += Money::cents($row->amount);
            }
        });

        return Money::decimal($sum);
    }

    private function sumCashBySource(Builder $query): array
    {
        $buckets = [
            'payment' => 0,
            'payment_reversal' => 0,
            'other_income' => 0,
            'capital_in' => 0,
            'capital_out' => 0,
            'land_purchase_payment' => 0,
            'expense_payment' => 0,
            'inflow' => 0,
            'outflow' => 0,
        ];
        (clone $query)->reorder()->chunkById(200, function ($rows) use (&$buckets) {
            foreach ($rows as $row) {
                $cents = Money::cents($row->signed_amount);
                if ($cents >= 0) {
                    $buckets['inflow'] += $cents;
                } else {
                    $buckets['outflow'] += abs($cents);
                }
                if ($row->source_type === 'capital_transaction') {
                    if ($cents >= 0) {
                        $buckets['capital_in'] += $cents;
                    } else {
                        $buckets['capital_out'] += abs($cents);
                    }
                } elseif (isset($buckets[$row->source_type])) {
                    $buckets[$row->source_type] += abs($cents);
                }
            }
        });

        return array_map(fn ($v) => Money::decimal($v), $buckets);
    }

    private function financeCostSummary(array $f): array
    {
        $expenses = Expense::query();
        if (! empty($f['date_from'])) {
            $expenses->whereDate('occurred_on', '>=', $f['date_from']);
        }
        if (! empty($f['date_to'])) {
            $expenses->whereDate('occurred_on', '<=', $f['date_to']);
        }
        if (! empty($f['building_id'])) {
            $expenses->where('building_id', $f['building_id']);
        }
        if (! empty($f['land_id'])) {
            $landId = (int) $f['land_id'];
            $expenses->where(function ($q) use ($landId) {
                $q->where('land_id', $landId)
                    ->orWhereIn('building_id', function ($b) use ($landId) {
                        $b->select('id')->from('buildings')->where('land_id', $landId);
                    });
            });
        }

        $expenseAmount = 0;
        $expensePaid = 0;
        foreach ((clone $expenses)->withSum('payments as paid_sum', 'amount')->get(['id', 'amount']) as $expense) {
            $expenseAmount += Money::cents($expense->amount);
            $expensePaid += Money::cents($expense->paid_sum ?? 0);
        }

        $lands = Land::query();
        if (! empty($f['land_id'])) {
            $lands->where('id', $f['land_id']);
        }
        if (! empty($f['building_id'])) {
            $lands->whereHas('buildings', fn ($q) => $q->where('buildings.id', $f['building_id']));
        }

        if (! empty($f['date_from'])) {
            $lands->whereDate('purchase_date', '>=', $f['date_from']);
        }
        if (! empty($f['date_to'])) {
            $lands->whereDate('purchase_date', '<=', $f['date_to']);
        }

        $landPrice = 0;
        $landPaid = 0;
        foreach ($lands->get(['id', 'purchase_price']) as $land) {
            $landPrice += Money::cents($land->purchase_price);
            $landPaid += Money::cents(LandPurchasePayment::where('land_id', $land->id)->sum('amount'));
        }

        // Unpaid (current balances, not period-only)
        $unpaidExpenseQuery = Expense::query()->whereIn('status', ['unpaid', 'partial']);
        if (! empty($f['building_id'])) {
            $unpaidExpenseQuery->where('building_id', $f['building_id']);
        }
        if (! empty($f['land_id'])) {
            $landId = (int) $f['land_id'];
            $unpaidExpenseQuery->where(function ($q) use ($landId) {
                $q->where('land_id', $landId)
                    ->orWhereIn('building_id', function ($b) use ($landId) {
                        $b->select('id')->from('buildings')->where('land_id', $landId);
                    });
            });
        }
        $unpaidExpenses = 0;
        foreach ($unpaidExpenseQuery->withSum('payments as paid_sum', 'amount')->get(['id', 'amount']) as $expense) {
            $unpaidExpenses += max(0, Money::cents($expense->amount) - Money::cents($expense->paid_sum ?? 0));
        }

        $unpaidLands = 0;
        $unpaidLandQuery = Land::query();
        if (! empty($f['land_id'])) {
            $unpaidLandQuery->where('id', $f['land_id']);
        }
        if (! empty($f['building_id'])) {
            $unpaidLandQuery->whereHas('buildings', fn ($q) => $q->where('buildings.id', $f['building_id']));
        }
        foreach ($unpaidLandQuery->get(['id', 'purchase_price']) as $land) {
            $paid = Money::cents(LandPurchasePayment::where('land_id', $land->id)->sum('amount'));
            $unpaidLands += max(0, Money::cents($land->purchase_price) - $paid);
        }

        return [
            'expenses_recorded' => Money::decimal($expenseAmount),
            'expenses_paid' => Money::decimal($expensePaid),
            'expenses_remaining' => Money::decimal(max(0, $expenseAmount - $expensePaid)),
            'land_purchase_price' => Money::decimal($landPrice),
            'land_purchase_paid' => Money::decimal($landPaid),
            'land_purchase_remaining' => Money::decimal(max(0, $landPrice - $landPaid)),
            'unpaid_expenses' => Money::decimal($unpaidExpenses),
            'unpaid_land_purchases' => Money::decimal($unpaidLands),
            'unpaid_costs' => Money::decimal($unpaidExpenses + $unpaidLands),
        ];
    }

    private function capitalPeriodSummary(array $f): array
    {
        $q = CapitalTransaction::query();
        $this->dates($q, $f, 'occurred_on');
        // Capital is company-level; hide when scoped to building/land
        if (! empty($f['building_id']) || ! empty($f['land_id'])) {
            return [
                'opening_balance' => '0.00',
                'additions' => '0.00',
                'withdrawals' => '0.00',
            ];
        }
        $opening = 0;
        $additions = 0;
        $withdrawals = 0;
        foreach ($q->get(['type', 'amount']) as $row) {
            $cents = Money::cents($row->amount);
            if ($row->type === 'opening_balance') {
                $opening += $cents;
            } elseif ($row->type === 'capital_addition') {
                $additions += $cents;
            } elseif ($row->type === 'owner_withdrawal') {
                $withdrawals += $cents;
            }
        }

        return [
            'opening_balance' => Money::decimal($opening),
            'additions' => Money::decimal($additions),
            'withdrawals' => Money::decimal($withdrawals),
        ];
    }

    public function summary(array $f): array
    {
        $all = $this->contracts($f);
        $signed = (clone $all)->whereNotIn('status', ['draft', 'cancelled']);
        $rentDues = Due::where('is_void', false)->where('kind', 'rent')->whereHas('contract', function ($q) use ($f) {
            $q->whereNotIn('status', ['draft', 'cancelled']);
            $this->dates($q, $f, 'start_date');
            if (! empty($f['building_id'])) {
                $q->whereHas('unit', fn ($q) => $q->where('building_id', $f['building_id']));
            }
        });

        $cash = $this->sumCashBySource($this->cashMovements($f));
        $costs = $this->financeCostSummary($f);
        $capital = $this->capitalPeriodSummary($f);

        return [
            'collections' => app(CollectionSummaryService::class)->summarize($this->payments($f)),
            'dues' => app(DueBalanceService::class)->summarize($this->dues($f)),
            'contracts' => ['all_count' => (clone $all)->count(), 'signed_count' => (clone $signed)->count(),
                'draft_count' => (clone $all)->where('status', 'draft')->count(), 'cancelled_count' => (clone $all)->where('status', 'cancelled')->count(),
                'sale_count' => (clone $signed)->where('type', 'sale')->count(), 'rental_count' => (clone $signed)->where('type', 'rental')->count(),
                'signed_sales_value' => $this->sumMoney((clone $signed)->where('type', 'sale')),
                'rental_scheduled_value' => $this->sumMoney($rentDues),
                'missing_schedules_count' => (clone $signed)->doesntHave('schedules')->count(),
            ],
            'finance' => [
                'cash_balance' => app(CashLedgerService::class)->balance(),
                'period_inflow' => $cash['inflow'],
                'period_outflow' => $cash['outflow'],
                'period_net' => Money::decimal(Money::cents($cash['inflow']) - Money::cents($cash['outflow'])),
                'by_source' => [
                    'contract_collections' => $cash['payment'],
                    'payment_reversals' => $cash['payment_reversal'],
                    'other_income' => $cash['other_income'],
                    'capital_in' => $cash['capital_in'],
                    'capital_out' => $cash['capital_out'],
                    'land_purchase_payments' => $cash['land_purchase_payment'],
                    'expense_payments' => $cash['expense_payment'],
                ],
                'capital' => $capital,
                'costs' => $costs,
            ],
        ];
    }

    public function rows($models, string $type): array
    {
        if ($type === 'finance') {
            return $models->map(fn (CashTransaction $row) => $this->financeRow($row))->all();
        }

        return $models->map(function ($row) use ($type) {
            $c = $type === 'contracts' ? $row : $row->contract;
            $base = ['id' => $row->id, 'contract_id' => $c->id, 'contract_number' => $c->number,
                'customer_id' => $c->customer_id, 'customer_name' => $c->customer->name,
                'unit_id' => $c->unit_id, 'unit_number' => $c->unit->number, 'building_id' => $c->unit->building_id, 'building_name' => $c->unit->building->name];
            if ($type === 'collections') {
                return array_merge($base, ['receipt_number' => $row->receipt_number, 'paid_on' => $row->paid_on->toDateString(), 'method' => $row->method, 'amount' => $row->amount, 'status' => $row->status]);
            }
            if ($type === 'dues') {
                $due = (new DueResource($row))->resolve(request());

                return array_merge($base, array_intersect_key($due, array_flip(['label', 'kind', 'due_date', 'amount', 'paid', 'remaining', 'status', 'is_overdue'])));
            }

            return array_merge($base, ['type' => $c->type, 'status' => $c->effective_status, 'start_date' => $c->start_date->toDateString(), 'end_date' => $c->effective_end_date?->toDateString(), 'amount' => $c->amount, 'frequency' => $c->frequency, 'sale_mode' => $c->sale_mode]);
        })->all();
    }

    private function financeRow(CashTransaction $row): array
    {
        $payload = [
            'id' => $row->id,
            'direction' => $row->direction,
            'signed_amount' => $row->signed_amount,
            'amount' => Money::decimal(abs(Money::cents($row->signed_amount))),
            'occurred_on' => $row->occurred_on?->toDateString(),
            'source_type' => $row->source_type,
            'source_id' => $row->source_id,
            'label' => $row->label,
            'notes' => $row->notes,
            'building_id' => null,
            'building_name' => null,
            'land_id' => null,
            'land_name' => null,
            'payment_id' => null,
            'expense_id' => null,
            'href' => null,
        ];

        if (in_array($row->source_type, ['payment', 'payment_reversal'], true)) {
            $payment = Payment::with('contract.unit.building')->find($row->source_id);
            if ($payment?->contract?->unit?->building) {
                $payload['payment_id'] = $payment->id;
                $payload['building_id'] = $payment->contract->unit->building_id;
                $payload['building_name'] = $payment->contract->unit->building->name;
                $payload['land_id'] = $payment->contract->unit->building->land_id;
                $payload['href'] = 'payments/'.$payment->id;
            }
        } elseif ($row->source_type === 'expense_payment') {
            $payment = ExpensePayment::with(['expense.building', 'expense.land'])->find($row->source_id);
            if ($payment?->expense) {
                $payload['expense_id'] = $payment->expense_id;
                $payload['building_id'] = $payment->expense->building_id;
                $payload['building_name'] = $payment->expense->building?->name;
                $payload['land_id'] = $payment->expense->land_id;
                $payload['land_name'] = $payment->expense->land?->name;
                if ($payment->expense->building_id) {
                    $payload['href'] = 'buildings/'.$payment->expense->building_id;
                } elseif ($payment->expense->land_id) {
                    $payload['href'] = 'lands/'.$payment->expense->land_id;
                } else {
                    $payload['href'] = 'finance';
                }
            }
        } elseif ($row->source_type === 'land_purchase_payment') {
            $payment = LandPurchasePayment::with('land')->find($row->source_id);
            if ($payment?->land) {
                $payload['land_id'] = $payment->land_id;
                $payload['land_name'] = $payment->land->name;
                $payload['href'] = 'lands/'.$payment->land_id;
            }
        } elseif ($row->source_type === 'other_income') {
            $income = OtherIncome::with(['building', 'land'])->find($row->source_id);
            if ($income) {
                $payload['building_id'] = $income->building_id;
                $payload['building_name'] = $income->building?->name;
                $payload['land_id'] = $income->land_id;
                $payload['land_name'] = $income->land?->name;
                $payload['href'] = 'finance';
            }
        } else {
            $payload['href'] = 'finance';
        }

        return $payload;
    }

    public function metadata(array $f): array
    {
        $buildingName = ! empty($f['building_id']) ? Building::findOrFail($f['building_id'])->name : null;
        $landName = ! empty($f['land_id']) ? Land::findOrFail($f['land_id'])->name : null;

        return [
            'type' => $f['type'],
            'filters' => [
                'date_from' => $f['date_from'],
                'date_to' => $f['date_to'],
                'building_id' => isset($f['building_id']) ? (int) $f['building_id'] : null,
                'building_name' => $buildingName,
                'land_id' => isset($f['land_id']) ? (int) $f['land_id'] : null,
                'land_name' => $landName,
            ],
            'as_of' => today()->toDateString(),
            'generated_at' => now()->toIso8601String(),
            'summary' => $this->summary($f),
        ];
    }
}
