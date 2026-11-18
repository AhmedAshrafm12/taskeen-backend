<?php

namespace Tests\Feature;

use App\Models\Building;
use App\Models\User;
use App\Services\PaymentService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class FinanceModuleTest extends TestCase
{
    use RefreshDatabase;

    private function owner(): User
    {
        return User::factory()->create([
            'email' => 'owner@example.com',
            'password' => Hash::make('correct-password'),
        ]);
    }

    private function actingOwner(): User
    {
        $user = $this->owner();
        Sanctum::actingAs($user);

        return $user;
    }

    public function test_opening_balance_capital_and_negative_cash_block(): void
    {
        $this->actingOwner();
        $this->getJson('/api/finance/summary')->assertOk()
            ->assertJsonPath('data.has_opening_balance', false)
            ->assertJsonPath('data.cash_balance', '0.00');

        $this->postJson('/api/finance/opening-balance', [
            'amount' => 10000000,
            'occurred_on' => '2026-01-01',
        ])->assertCreated()->assertJsonPath('data.type', 'opening_balance');

        $this->postJson('/api/finance/opening-balance', [
            'amount' => 1,
            'occurred_on' => '2026-01-02',
        ])->assertUnprocessable();

        $this->getJson('/api/finance/summary')->assertOk()
            ->assertJsonPath('data.has_opening_balance', true)
            ->assertJsonPath('data.cash_balance', '10000000.00');

        $this->postJson('/api/finance/capital-additions', [
            'amount' => 1000000,
            'occurred_on' => '2026-01-03',
        ])->assertCreated();
        $this->getJson('/api/finance/summary')->assertJsonPath('data.cash_balance', '11000000.00');

        $this->postJson('/api/finance/owner-withdrawals', [
            'amount' => 500000,
            'occurred_on' => '2026-01-04',
        ])->assertCreated();
        $this->getJson('/api/finance/summary')->assertJsonPath('data.cash_balance', '10500000.00');

        $this->postJson('/api/finance/owner-withdrawals', [
            'amount' => 20000000,
            'occurred_on' => '2026-01-05',
        ])->assertUnprocessable()->assertJsonValidationErrors('amount');
    }

    public function test_land_purchase_payments_and_building_link(): void
    {
        $this->actingOwner();
        $this->postJson('/api/finance/opening-balance', ['amount' => 8000000, 'occurred_on' => '2026-01-01'])->assertCreated();

        $landId = $this->postJson('/api/lands', [
            'name' => 'أرض المعادي',
            'location' => 'القاهرة',
            'purchase_price' => 3000000,
            'purchase_date' => '2026-01-02',
        ])->assertCreated()->json('data.id');

        $this->postJson("/api/lands/{$landId}/purchase-payments", [
            'amount' => 2000000,
            'paid_on' => '2026-01-03',
        ])->assertCreated();
        $this->getJson('/api/finance/summary')->assertJsonPath('data.cash_balance', '6000000.00');

        $this->postJson("/api/lands/{$landId}/purchase-payments", [
            'amount' => 1000000,
            'paid_on' => '2026-01-04',
        ])->assertCreated();
        $this->getJson('/api/finance/summary')->assertJsonPath('data.cash_balance', '5000000.00');

        $this->postJson("/api/lands/{$landId}/purchase-payments", [
            'amount' => 1,
            'paid_on' => '2026-01-05',
        ])->assertUnprocessable();

        $buildingId = $this->postJson('/api/buildings', [
            'name' => 'مبنى النيل',
            'address' => 'المعادي',
            'land_id' => $landId,
        ])->assertCreated()->json('data.id');

        $this->assertDatabaseHas('buildings', ['id' => $buildingId, 'land_id' => $landId]);
        $this->getJson("/api/lands/{$landId}")->assertOk()
            ->assertJsonPath('data.buildings_count', 1)
            ->assertJsonPath('data.purchase_remaining', '0.00');
    }

    public function test_expense_partial_payment_reduces_cash_only_by_paid_amount(): void
    {
        $this->actingOwner();
        $this->postJson('/api/finance/opening-balance', ['amount' => 1000000, 'occurred_on' => '2026-01-01'])->assertCreated();

        $expenseId = $this->postJson('/api/expenses', [
            'category' => 'labor',
            'description' => 'مقاول هيكل',
            'amount' => 500000,
            'occurred_on' => '2026-01-02',
            'pay_now' => 200000,
            'paid_on' => '2026-01-02',
        ])->assertCreated()->json('data.id');

        $this->getJson('/api/finance/summary')->assertJsonPath('data.cash_balance', '800000.00');
        $this->getJson("/api/expenses/{$expenseId}")->assertOk()
            ->assertJsonPath('data.status', 'partial')
            ->assertJsonPath('data.remaining', '300000.00');

        $this->postJson("/api/expenses/{$expenseId}/payments", [
            'amount' => 300000,
            'paid_on' => '2026-01-03',
        ])->assertCreated();

        $this->getJson('/api/finance/summary')->assertJsonPath('data.cash_balance', '500000.00');
        $this->getJson("/api/expenses/{$expenseId}")->assertJsonPath('data.status', 'paid');
    }

    public function test_other_income_and_cash_ledger_list(): void
    {
        $this->actingOwner();
        $this->postJson('/api/finance/opening-balance', ['amount' => 1000, 'occurred_on' => '2026-01-01'])->assertCreated();
        $this->postJson('/api/other-incomes', [
            'description' => 'بيع خردة',
            'amount' => 250.5,
            'occurred_on' => '2026-01-02',
        ])->assertCreated();

        $this->getJson('/api/finance/summary')->assertJsonPath('data.cash_balance', '1250.50');
        $this->getJson('/api/finance/cash-transactions')->assertOk()->assertJsonPath('meta.total', 2);
    }

    public function test_payment_collection_posts_cash_and_reversal_corrects_once(): void
    {
        $this->travelTo(Carbon::parse('2026-11-08 12:00:00', 'Africa/Cairo'));
        $this->actingOwner();
        $this->postJson('/api/finance/opening-balance', ['amount' => 0, 'occurred_on' => '2026-11-01'])->assertCreated();

        $building = Building::create(['name' => 'مبنى', 'address' => 'عنوان']);
        $unit = \App\Models\Unit::create(['building_id' => $building->id, 'number' => '101']);
        $customer = \App\Models\Customer::create(['name' => 'عميل', 'phone' => '01011111111']);
        $contractId = $this->postJson('/api/contracts', [
            'unit_id' => $unit->id,
            'customer_id' => $customer->id,
            'type' => 'sale',
            'sale_mode' => 'cash',
            'status' => 'active',
            'start_date' => '2026-11-01',
            'amount' => '1000.00',
            'request_key' => (string) Str::uuid(),
        ])->assertCreated()->json('data.id');

        $contract = \App\Models\Contract::findOrFail($contractId);
        $ctx = app(PaymentService::class)->context($contract);
        $dueId = $ctx['dues'][0]['id'];
        $paymentId = $this->postJson('/api/payments', [
            'contract_id' => $contract->id,
            'amount' => '1000.00',
            'paid_on' => '2026-11-08',
            'method' => 'cash',
            'expected_principal_remaining' => $ctx['remaining']['principal'],
            'expected_deposit_remaining' => $ctx['remaining']['deposit'],
            'expected_schedule_version' => $ctx['schedule_version'],
            'request_key' => (string) Str::uuid(),
            'allocations' => [[
                'due_id' => $dueId,
                'amount' => '1000.00',
                'expected_remaining' => $ctx['dues'][0]['remaining'],
            ]],
        ])->assertCreated()->json('data.id');

        $this->getJson('/api/finance/summary')->assertJsonPath('data.cash_balance', '1000.00');

        $this->postJson('/api/payments/'.$paymentId.'/reverse', [
            'reason' => 'خطأ إدخال',
            'request_key' => (string) Str::uuid(),
        ])->assertOk();

        $this->getJson('/api/finance/summary')->assertJsonPath('data.cash_balance', '0.00');
        $this->assertDatabaseCount('cash_transactions', 2);
    }
}
