<?php

namespace App\Http\Resources;

use App\Support\Money;
use Illuminate\Http\Resources\Json\JsonResource;

class CustomerResource extends JsonResource
{
    public function toArray($request): array
    {
        $remaining = $this->principal_remaining ?? null;
        $overdue = $this->principal_overdue ?? null;

        return [
            'id' => $this->id, 'name' => $this->name, 'phone' => $this->phone,
            'email' => $this->email, 'address' => $this->address, 'notes' => $this->notes,
            'created_at' => $this->created_at?->toIso8601String(),
            'contracts_count' => (int) ($this->contracts_count ?? 0),
            'active_contracts_count' => (int) ($this->active_contracts_count ?? 0),
            'principal_remaining' => Money::decimal(Money::cents($remaining ?? 0)),
            'principal_overdue' => Money::decimal(Money::cents($overdue ?? 0)),
            'last_payment_on' => $this->last_payment_on ? (string) $this->last_payment_on : null,
        ];
    }
}
