<?php

namespace App\Services;

use App\Models\Building;
use App\Models\Land;
use App\Models\LandPurchasePayment;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class LandService
{
    public function __construct(private CashLedgerService $cash) {}

    public function create(array $input): Land
    {
        return Land::create([
            'name' => trim($input['name']),
            'location' => trim($input['location']),
            'area' => $input['area'] ?? null,
            'purchase_date' => $input['purchase_date'] ?? null,
            'purchase_price' => Money::decimal(Money::cents($input['purchase_price'] ?? 0)),
            'status' => $input['status'] ?? 'vacant',
            'notes' => $input['notes'] ?? null,
        ]);
    }

    public function update(Land $land, array $input): Land
    {
        $land->update([
            'name' => trim($input['name']),
            'location' => trim($input['location']),
            'area' => $input['area'] ?? null,
            'purchase_date' => $input['purchase_date'] ?? null,
            'purchase_price' => Money::decimal(Money::cents($input['purchase_price'] ?? $land->purchase_price)),
            'status' => $input['status'] ?? $land->status,
            'notes' => $input['notes'] ?? null,
        ]);

        return $land->fresh();
    }

    public function recordPurchasePayment(Land $land, array $input, int $userId): LandPurchasePayment
    {
        return DB::transaction(function () use ($land, $input, $userId) {
            $this->cash->lockForUpdate();
            $land = Land::lockForUpdate()->findOrFail($land->id);
            $cents = Money::cents($input['amount']);
            if ($cents <= 0) {
                throw ValidationException::withMessages(['amount' => 'مبلغ دفعة الشراء يجب أن يكون أكبر من صفر.']);
            }
            $paid = Money::cents($land->purchasePayments()->sum('amount'));
            $price = Money::cents($land->purchase_price);
            if ($price > 0 && $paid + $cents > $price) {
                throw ValidationException::withMessages([
                    'amount' => 'إجمالي دفعات الشراء يتجاوز سعر الأرض ('.Money::decimal($price).' ج.م). المتبقي '.Money::decimal(max(0, $price - $paid)).' ج.م.',
                ]);
            }
            $this->cash->assertCanDebit($cents);
            $payment = LandPurchasePayment::create([
                'land_id' => $land->id,
                'amount' => Money::decimal($cents),
                'paid_on' => $input['paid_on'],
                'notes' => $input['notes'] ?? null,
                'user_id' => $userId,
            ]);
            $this->cash->post('out', $cents, $input['paid_on'], 'land_purchase_payment', $payment->id, 'دفعة شراء أرض: '.$land->name, $payment->notes);

            return $payment;
        }, 3);
    }

    public function linkBuilding(Land $land, int $buildingId): Building
    {
        return DB::transaction(function () use ($land, $buildingId) {
            $building = Building::lockForUpdate()->findOrFail($buildingId);
            $building->update(['land_id' => $land->id]);
            $land->refreshStatus();

            return $building->fresh();
        }, 3);
    }

    public function unlinkBuilding(Building $building): Building
    {
        return DB::transaction(function () use ($building) {
            $building = Building::lockForUpdate()->findOrFail($building->id);
            $landId = $building->land_id;
            $building->update(['land_id' => null]);
            if ($landId) {
                Land::find($landId)?->refreshStatus();
            }

            return $building->fresh();
        }, 3);
    }
}
