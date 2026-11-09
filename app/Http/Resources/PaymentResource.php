<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class PaymentResource extends JsonResource
{
    public function toArray($request): array
    {
        return ['id' => $this->id, 'contract_id' => $this->contract_id, 'receipt_number' => $this->receipt_number, 'amount' => $this->amount,
            'paid_on' => $this->paid_on->toDateString(), 'method' => $this->method, 'reference' => $this->reference, 'notes' => $this->notes, 'status' => $this->status,
            'reversed_at' => $this->reversed_at?->toIso8601String(), 'reversal_reason' => $this->reversal_reason,
            'corrects_payment_id' => $this->corrects_payment_id, 'replacement_payment_id' => $this->whenLoaded('replacement', fn () => $this->replacement?->id),
            'replacement_receipt_number' => $this->whenLoaded('replacement', fn () => $this->replacement?->receipt_number),
            'receipt' => $this->receipt_snapshot,
            'contract' => $this->whenLoaded('contract', fn () => ['id' => $this->contract->id, 'number' => $this->contract->number, 'customer' => $this->contract->customer->only('id', 'name', 'phone'),
                'unit' => ['id' => $this->contract->unit_id, 'number' => $this->contract->unit->number, 'building_name' => $this->contract->unit->building->name]]),
            'allocations' => $this->whenLoaded('allocations', fn () => $this->allocations->map(fn ($a) => ['due_id' => $a->due_id, 'amount' => $a->amount, 'label' => $a->due->label, 'kind' => $a->due->kind, 'due_date' => $a->due->due_date->toDateString()])),
            'history' => $this->whenLoaded('events', fn () => $this->events->map(fn ($e) => ['id' => $e->id, 'action' => $e->action, 'reason' => $e->reason, 'replacement_payment_id' => $e->replacement_payment_id, 'created_at' => $e->created_at->toIso8601String()])),
        ];
    }
}
