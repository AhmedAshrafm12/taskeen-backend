<?php

namespace App\Http\Resources;

use App\Support\Money;
use Illuminate\Http\Resources\Json\JsonResource;

class DueResource extends JsonResource
{
    public function toArray($request): array
    {
        $paid = $this->resource->paidCents();
        $remaining = max(0, Money::cents($this->amount) - $paid);
        $overdue = ! $this->is_void && $remaining > 0 && $this->due_date->lt(today());
        $status = $this->is_void ? 'voided' : ($remaining === 0 ? 'paid' : ($paid > 0 ? 'partial' : ($overdue ? 'overdue' : ($this->due_date->isSameDay(today()) ? 'today' : 'upcoming'))));

        return [
            'id' => $this->id, 'contract_id' => $this->contract_id, 'schedule_id' => $this->schedule_id, 'label' => $this->label, 'kind' => $this->kind,
            'due_date' => $this->due_date->toDateString(), 'amount' => $this->amount, 'paid' => Money::decimal($paid), 'remaining' => $this->is_void ? '0.00' : Money::decimal($remaining),
            'status' => $status, 'is_overdue' => $overdue, 'is_void' => $this->is_void, 'void_reason' => $this->void_reason,
            'period_start' => $this->period_start?->toDateString(), 'period_end' => $this->period_end?->toDateString(),
            'contract' => $this->whenLoaded('contract', fn () => ['id' => $this->contract->id, 'number' => $this->contract->number, 'type' => $this->contract->type,
                'customer' => $this->contract->customer->only('id', 'name', 'phone'),
                'unit' => ['id' => $this->contract->unit->id, 'number' => $this->contract->unit->number, 'building_name' => $this->contract->unit->building->name],
            ]),
        ];
    }
}
