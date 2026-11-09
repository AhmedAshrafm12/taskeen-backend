<?php

namespace App\Services;

use App\Support\Money;

class CollectionSummaryService
{
    public function summarize($query): array
    {
        $totals = ['collected_total' => 0, 'principal_collected' => 0, 'deposit_collected' => 0, 'reversed_total' => 0];
        (clone $query)->setEagerLoads([])->with('allocations.due')->reorder()->chunkById(100, function ($payments) use (&$totals) {
            foreach ($payments as $p) {
                if ($p->status !== 'posted') {
                    $totals['reversed_total'] += Money::cents($p->amount);

                    continue;
                }
                $totals['collected_total'] += Money::cents($p->amount);
                foreach ($p->allocations as $a) {
                    $totals[$a->due->kind === 'security_deposit' ? 'deposit_collected' : 'principal_collected'] += Money::cents($a->amount);
                }
            }
        }, 'payments.id', 'id');

        return array_map(fn ($v) => Money::decimal($v), $totals);
    }
}
