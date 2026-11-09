<?php

namespace App\Services;

use App\Http\Resources\DueResource;
use App\Models\Building;
use App\Models\Contract;
use App\Models\Due;
use App\Models\Payment;
use App\Support\Money;

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

    public function details(array $f)
    {
        return match ($f['type']) {
            'collections' => $this->payments($f)->orderByDesc('paid_on')->orderByDesc('id'),
            'dues' => $this->dues($f)->orderBy('due_date')->orderBy('id'),
            'contracts' => $this->contracts($f)->orderByDesc('start_date')->orderByDesc('id'),
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
        ];
    }

    public function rows($models, string $type): array
    {
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

    public function metadata(array $f): array
    {
        return ['type' => $f['type'], 'filters' => ['date_from' => $f['date_from'], 'date_to' => $f['date_to'], 'building_id' => isset($f['building_id']) ? (int) $f['building_id'] : null, 'building_name' => ! empty($f['building_id']) ? Building::findOrFail($f['building_id'])->name : null],
            'as_of' => today()->toDateString(), 'generated_at' => now()->toIso8601String(), 'summary' => $this->summary($f)];
    }
}
