<?php

namespace App\Services;

use App\Support\Money;

class DueBalanceService
{
    public function summarize($query): array
    {
        $keys = ['scheduled', 'paid', 'remaining', 'due_now', 'overdue', 'upcoming'];
        $totals = ['principal' => array_fill_keys($keys, 0), 'deposit' => array_fill_keys($keys, 0)];
        $today = today()->toDateString();
        (clone $query)->setEagerLoads([])->with('allocations.payment')->reorder()->chunkById(200, function ($dues) use (&$totals, $today) {
            foreach ($dues as $due) {
                if ($due->is_void) {
                    continue;
                }
                $bucket = $due->kind === 'security_deposit' ? 'deposit' : 'principal';
                $paid = $due->paidCents();
                $remaining = max(0, Money::cents($due->amount) - $paid);
                $date = $due->due_date->toDateString();
                $totals[$bucket]['scheduled'] += Money::cents($due->amount);
                $totals[$bucket]['paid'] += $paid;
                $totals[$bucket]['remaining'] += $remaining;
                $totals[$bucket][$date <= $today ? 'due_now' : 'upcoming'] += $remaining;
                if ($date < $today) {
                    $totals[$bucket]['overdue'] += $remaining;
                }
            }
        }, 'dues.id', 'id');
        foreach ($totals as &$bucket) {
            foreach ($bucket as &$value) {
                $value = Money::decimal($value);
            }
        }

        return $totals;
    }
}
