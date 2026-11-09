<?php

namespace App\Http\Resources;

use App\Support\Money;
use Illuminate\Http\Resources\Json\JsonResource;

class ContractDueSummaryResource extends JsonResource
{
    public function toArray($request): array
    {
        $row = $this->resource;

        return [
            'contract_id' => $row['contract_id'],
            'contract_number' => $row['contract_number'],
            'type' => $row['type'],
            'status' => $row['status'],
            'customer' => $row['customer'],
            'unit' => $row['unit'],
            'principal' => [
                'scheduled' => Money::decimal($row['principal_scheduled']),
                'paid' => Money::decimal($row['principal_paid']),
                'remaining' => Money::decimal($row['principal_remaining']),
                'overdue' => Money::decimal($row['principal_overdue']),
                'due_now' => Money::decimal($row['principal_due_now']),
            ],
            'deposit' => [
                'remaining' => Money::decimal($row['deposit_remaining']),
                'overdue' => Money::decimal($row['deposit_overdue']),
            ],
            'open_dues_count' => $row['open_dues_count'],
            'overdue_dues_count' => $row['overdue_dues_count'],
            'next_due_date' => $row['next_due_date'],
            'progress_percent' => $row['progress_percent'],
        ];
    }
}
