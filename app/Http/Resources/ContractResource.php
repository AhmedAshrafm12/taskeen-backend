<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class ContractResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id, 'number' => $this->number, 'unit_id' => $this->unit_id, 'customer_id' => $this->customer_id,
            'type' => $this->type, 'status' => $this->status, 'effective_status' => $this->effective_status, 'sale_mode' => $this->sale_mode,
            'start_date' => $this->start_date->toDateString(), 'end_date' => $this->end_date?->toDateString(),
            'effective_end_date' => $this->effective_end_date?->toDateString(), 'amount' => $this->amount,
            'down_payment' => $this->down_payment, 'security_deposit' => $this->security_deposit,
            'frequency' => $this->frequency, 'installments_count' => $this->installments_count,
            'first_due_date' => $this->first_due_date?->toDateString(), 'notes' => $this->notes, 'renewed_from_id' => $this->renewed_from_id,
            'unit' => $this->whenLoaded('unit', fn () => new UnitResource($this->unit)),
            'customer' => $this->whenLoaded('customer', fn () => new CustomerResource($this->customer)),
            'history' => $this->whenLoaded('events', fn () => $this->events->map(fn ($e) => [
                'id' => $e->id, 'action' => $e->action, 'from_status' => $e->from_status, 'to_status' => $e->to_status,
                'reason' => $e->reason, 'effective_date' => $e->effective_date, 'created_at' => $e->created_at->toIso8601String(),
            ])),
        ];
    }
}
