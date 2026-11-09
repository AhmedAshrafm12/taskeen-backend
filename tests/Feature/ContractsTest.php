<?php

namespace Tests\Feature;

use App\Models\Building;
use App\Models\Contract;
use App\Models\Customer;
use App\Models\Unit;
use App\Models\User;
use App\Services\ContractService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ContractsTest extends TestCase
{
    use RefreshDatabase;

    private Unit $unit;

    private Customer $customer;

    private Building $building;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-11-08 12:00:00', 'Africa/Cairo'));
        $this->building = Building::create(['name' => 'برج النيل', 'address' => 'المعادي، القاهرة']);
        $this->unit = Unit::create(['building_id' => $this->building->id, 'number' => '١٠١', 'asking_sale_price' => '900000.00']);
        $this->customer = Customer::create(['name' => 'أحمد محمد', 'phone' => '01012345678']);
    }

    private function owner(): void
    {
        Sanctum::actingAs(User::factory()->create());
    }

    private function sale(array $overrides = []): array
    {
        return array_merge(['unit_id' => $this->unit->id, 'customer_id' => $this->customer->id, 'type' => 'sale', 'sale_mode' => 'cash', 'status' => 'active', 'start_date' => '2026-11-08', 'amount' => '1000000.25', 'request_key' => (string) Str::uuid()], $overrides);
    }

    private function rent(array $overrides = []): array
    {
        return array_merge(['unit_id' => $this->unit->id, 'customer_id' => $this->customer->id, 'type' => 'rental', 'status' => 'active', 'start_date' => '2026-11-01', 'end_date' => '2026-11-30', 'amount' => '12000.50', 'frequency' => 'monthly', 'security_deposit' => '5000.25', 'request_key' => (string) Str::uuid()], $overrides);
    }

    private function action(int $id, string $action, array $overrides = [])
    {
        return $this->postJson('/api/contracts/'.$id.'/transition', array_merge(['action' => $action, 'reason' => 'مراجعة المالك وتأكيد الإجراء', 'request_key' => (string) Str::uuid()], $overrides));
    }

    public function test_endpoints_require_authentication(): void
    {
        $this->getJson('/api/contracts')->assertUnauthorized();
        $this->postJson('/api/contracts')->assertUnauthorized();
        $this->getJson('/api/contracts/1')->assertUnauthorized();
        $this->postJson('/api/contracts/1/transition')->assertUnauthorized();
    }

    public function test_cash_sale_has_independent_terms_history_and_live_unit_counts(): void
    {
        $this->owner();
        $response = $this->postJson('/api/contracts', $this->sale())->assertCreated()->assertJsonPath('data.amount', '1000000.25')
            ->assertJsonPath('data.customer.name', 'أحمد محمد')->assertJsonPath('data.unit.status', 'sold')->assertJsonCount(1, 'data.history');
        $id = $response->json('data.id');
        $this->unit->update(['asking_sale_price' => '1100000.00']);
        $this->getJson('/api/contracts/'.$id)->assertOk()->assertJsonPath('data.amount', '1000000.25')->assertJsonMissingPath('data.request_key')->assertJsonMissingPath('data.request_hash');
        $this->getJson('/api/units/'.$this->unit->id)->assertJsonPath('data.status', 'sold')->assertJsonPath('data.identity_locked', true);
        $this->getJson('/api/units?status=sold')->assertJsonCount(1, 'data');
        $this->getJson('/api/buildings/'.$this->building->id)->assertJsonPath('data.status_counts.sold', 1)->assertJsonPath('data.status_counts.available', 0);
        $this->getJson('/api/buildings/'.$this->building->id.'/units')->assertJsonPath('data.0.status', 'sold');
        $this->putJson('/api/contracts/'.$id, ['amount' => 1])->assertStatus(405);
        $this->deleteJson('/api/contracts/'.$id)->assertStatus(405);
    }

    public function test_installment_terms_are_validated_without_treating_down_payment_as_a_collection(): void
    {
        $this->owner();
        $this->postJson('/api/contracts', $this->sale(['sale_mode' => 'installments']))->assertUnprocessable()->assertJsonValidationErrors(['frequency', 'installments_count', 'first_due_date']);
        $input = $this->sale(['sale_mode' => 'installments', 'down_payment' => '200000.01', 'installments_count' => 12, 'frequency' => 'quarterly', 'first_due_date' => '2026-12-01']);
        $this->postJson('/api/contracts', $input)->assertCreated()->assertJsonPath('data.down_payment', '200000.01')->assertJsonPath('data.frequency', 'quarterly')->assertJsonPath('data.installments_count', 12);
        $this->assertDatabaseCount('contracts', 1);
        $this->assertSame(80000024, ContractService::cents('1000000.25') - ContractService::cents('200000.01'));
    }

    public function test_invalid_amounts_dates_and_irrelevant_fields_are_rejected(): void
    {
        $this->owner();
        foreach ([['amount' => '0'], ['amount' => '1.001'], ['down_payment' => '1'], ['security_deposit' => '1'], ['frequency' => 'monthly'], ['start_date' => 'bad'], ['status' => 'completed'], ['unit_id' => 999999], ['customer_id' => 999999]] as $invalid) {
            $this->postJson('/api/contracts', $this->sale($invalid))->assertUnprocessable();
        }
        $this->postJson('/api/contracts', $this->sale(['sale_mode' => 'installments', 'amount' => '10.00', 'down_payment' => '10.00', 'frequency' => 'monthly', 'installments_count' => 1, 'first_due_date' => '2026-11-08']))->assertUnprocessable()->assertJsonValidationErrors('down_payment');
        $this->postJson('/api/contracts', $this->rent(['end_date' => '2026-10-31']))->assertUnprocessable()->assertJsonValidationErrors('end_date');
        $this->postJson('/api/contracts', $this->rent(['sale_mode' => 'cash']))->assertUnprocessable();
        $this->assertDatabaseCount('contracts', 0);
    }

    public function test_rental_conflicts_include_end_date_and_allow_the_next_day(): void
    {
        $this->owner();
        $this->postJson('/api/contracts', $this->rent())->assertCreated()->assertJsonPath('data.security_deposit', '5000.25');
        $this->postJson('/api/contracts', $this->rent(['start_date' => '2026-11-30', 'end_date' => '2026-12-31']))->assertUnprocessable()->assertJsonValidationErrors('start_date');
        $this->postJson('/api/contracts', $this->rent(['start_date' => '2026-12-01', 'end_date' => '2026-12-31', 'frequency' => 'yearly']))->assertCreated();
        $this->getJson('/api/units/'.$this->unit->id)->assertJsonPath('data.status', 'rented');
        $this->getJson('/api/units?status=available')->assertJsonCount(0, 'data');
        $this->getJson('/api/buildings/'.$this->building->id)->assertJsonPath('data.status_counts.rented', 1);
    }

    public function test_future_bookings_and_expiry_change_availability_without_manual_updates(): void
    {
        $this->owner();
        $id = $this->postJson('/api/contracts', $this->rent(['start_date' => '2026-12-01', 'end_date' => '2026-12-31']))->assertCreated()->json('data.id');
        $this->getJson('/api/units/'.$this->unit->id)->assertJsonPath('data.status', 'available')->assertJsonPath('data.identity_locked', true);
        $this->travelTo(Carbon::parse('2026-12-31 12:00:00', 'Africa/Cairo'));
        $this->getJson('/api/units/'.$this->unit->id)->assertJsonPath('data.status', 'rented');
        $this->travelTo(Carbon::parse('2027-01-01 12:00:00', 'Africa/Cairo'));
        $this->getJson('/api/units/'.$this->unit->id)->assertJsonPath('data.status', 'available');
        $this->getJson('/api/contracts/'.$id)->assertJsonPath('data.effective_status', 'ended');
        $this->getJson('/api/contracts?status=ended')->assertJsonCount(1, 'data');
        $this->getJson('/api/contracts?status=active')->assertJsonCount(0, 'data');
        $this->action($id, 'cancel')->assertUnprocessable();
        $this->action($id, 'complete')->assertOk()->assertJsonPath('data.status', 'ended');
    }

    public function test_drafts_do_not_reserve_and_activation_rechecks_conflicts(): void
    {
        $this->owner();
        $draft = $this->postJson('/api/contracts', $this->sale(['status' => 'draft']))->assertCreated()->json('data.id');
        $this->getJson('/api/units/'.$this->unit->id)->assertJsonPath('data.status', 'available')->assertJsonPath('data.identity_locked', false);
        $this->postJson('/api/contracts', $this->sale())->assertCreated();
        $this->action($draft, 'activate')->assertUnprocessable()->assertJsonValidationErrors('unit_id');
        $this->assertDatabaseHas('contracts', ['id' => $draft, 'status' => 'draft']);
    }

    public function test_draft_rental_activation_rechecks_inclusive_dates(): void
    {
        $this->owner();
        $draft = $this->postJson('/api/contracts', $this->rent(['status' => 'draft']))->assertCreated()->json('data.id');
        $this->postJson('/api/contracts', $this->rent(['start_date' => '2026-11-30', 'end_date' => '2026-12-31']))->assertCreated();
        $this->action($draft, 'activate')->assertUnprocessable()->assertJsonValidationErrors('start_date');
    }

    public function test_sale_completion_preserves_ownership_and_prevents_another_sale(): void
    {
        $this->owner();
        $id = $this->postJson('/api/contracts', $this->sale())->assertCreated()->json('data.id');
        $this->action($id, 'complete')->assertOk()->assertJsonPath('data.status', 'completed');
        $this->postJson('/api/contracts', $this->sale())->assertUnprocessable()->assertJsonValidationErrors('unit_id');
        $this->action($id, 'cancel')->assertUnprocessable();
        $this->getJson('/api/units/'.$this->unit->id)->assertJsonPath('data.status', 'sold');
    }

    public function test_sale_and_rental_periods_cannot_conflict(): void
    {
        $this->owner();
        $this->postJson('/api/contracts', $this->rent())->assertCreated();
        $this->postJson('/api/contracts', $this->sale())->assertUnprocessable()->assertJsonValidationErrors('start_date');
        $this->postJson('/api/contracts', $this->sale(['start_date' => '2026-12-01']))->assertCreated();
        $this->postJson('/api/contracts', $this->rent(['start_date' => '2026-12-01', 'end_date' => '2026-12-10']))->assertUnprocessable();
        $this->travelTo(Carbon::parse('2026-12-01 12:00:00', 'Africa/Cairo'));
        $this->getJson('/api/units/'.$this->unit->id)->assertJsonPath('data.status', 'sold');
    }

    public function test_termination_keeps_original_terms_and_preserves_past_occupancy(): void
    {
        $this->owner();
        $id = $this->postJson('/api/contracts', $this->rent())->assertCreated()->json('data.id');
        $this->action($id, 'terminate', ['effective_date' => '2026-11-09'])->assertUnprocessable();
        $this->action($id, 'terminate', ['effective_date' => '2026-11-07'])->assertOk()->assertJsonPath('data.end_date', '2026-11-30')->assertJsonPath('data.effective_end_date', '2026-11-07')->assertJsonPath('data.status', 'ended');
        $this->getJson('/api/units/'.$this->unit->id)->assertJsonPath('data.status', 'available');
        $this->postJson('/api/contracts', $this->rent(['start_date' => '2026-11-07', 'end_date' => '2026-11-08']))->assertUnprocessable();
        $this->postJson('/api/contracts', $this->rent(['start_date' => '2026-11-08']))->assertCreated();
        $this->assertDatabaseHas('contract_events', ['contract_id' => $id, 'action' => 'terminate', 'effective_date' => '2026-11-07']);
    }

    public function test_renewal_is_linked_and_only_one_non_cancelled_successor_is_allowed(): void
    {
        $this->owner();
        $id = $this->postJson('/api/contracts', $this->rent())->assertCreated()->json('data.id');
        $this->postJson('/api/contracts', $this->rent(['renewed_from_id' => $id, 'start_date' => '2026-11-30', 'end_date' => '2026-12-31']))->assertUnprocessable();
        $next = $this->rent(['renewed_from_id' => $id, 'start_date' => '2026-12-01', 'end_date' => '2026-12-31', 'amount' => '15000.25']);
        $renewed = $this->postJson('/api/contracts', $next)->assertCreated()->assertJsonPath('data.renewed_from_id', $id)->json('data.id');
        $this->postJson('/api/contracts', array_merge($next, ['request_key' => (string) Str::uuid()]))->assertUnprocessable()->assertJsonValidationErrors('renewed_from_id');
        $this->action($id, 'cancel')->assertUnprocessable();
        $this->action($id, 'terminate', ['effective_date' => '2026-11-07'])->assertUnprocessable();
        $this->action($renewed, 'cancel')->assertOk();
        $this->postJson('/api/contracts', array_merge($next, ['request_key' => (string) Str::uuid()]))->assertCreated();
        $source = Contract::findOrFail($id);
        $this->assertSame('12000.50', $source->amount);
        $this->assertSame('2026-11-30', $source->end_date->toDateString());
    }

    public function test_duplicate_requests_are_replayed_without_duplicate_contracts_or_events(): void
    {
        $this->owner();
        $input = $this->sale();
        $id = $this->postJson('/api/contracts', $input)->assertCreated()->json('data.id');
        $this->postJson('/api/contracts', $input)->assertOk()->assertJsonPath('data.id', $id);
        $this->postJson('/api/contracts', array_merge($input, ['amount' => '1.00']))->assertStatus(409);
        $key = (string) Str::uuid();
        $this->action($id, 'cancel', ['request_key' => $key])->assertOk()->assertJsonPath('data.status', 'cancelled');
        $this->action($id, 'cancel', ['request_key' => $key])->assertOk();
        $this->action($id, 'cancel', ['request_key' => $key, 'reason' => 'different'])->assertStatus(409);
        $this->assertDatabaseCount('contracts', 1);
        $this->assertDatabaseCount('contract_events', 2);
        $this->getJson('/api/units/'.$this->unit->id)->assertJsonPath('data.status', 'available');
        $this->postJson('/api/contracts', $this->sale())->assertCreated();
    }

    public function test_inactive_buildings_and_historical_unit_identity_are_protected(): void
    {
        $this->owner();
        $this->building->update(['is_active' => false]);
        $this->postJson('/api/contracts', $this->sale())->assertUnprocessable()->assertJsonValidationErrors('unit_id');
        $id = $this->postJson('/api/contracts', $this->sale(['status' => 'draft']))->assertCreated()->json('data.id');
        $this->action($id, 'activate')->assertUnprocessable();
        $this->building->update(['is_active' => true]);
        $this->action($id, 'activate')->assertOk();
        $this->putJson('/api/units/'.$this->unit->id, ['building_id' => $this->building->id, 'number' => '102', 'floor' => 0, 'type' => 'apartment'])->assertUnprocessable();
        $this->putJson('/api/units/'.$this->unit->id, ['building_id' => $this->building->id, 'number' => '101', 'floor' => 0, 'type' => 'apartment', 'asking_sale_price' => '2000000.00'])->assertOk();
        $this->getJson('/api/contracts/'.$id)->assertJsonPath('data.amount', '1000000.25');
    }

    public function test_profile_history_search_filters_and_pagination(): void
    {
        $this->owner();
        for ($i = 0; $i < 22; $i++) {
            $this->postJson('/api/contracts', $this->sale(['status' => 'draft']))->assertCreated();
        }
        $this->getJson('/api/contracts')->assertJsonCount(20, 'data')->assertJsonPath('meta.total', 22);
        $this->getJson('/api/contracts?page=2')->assertJsonCount(2,'data');
        $this->getJson('/api/contracts?customer_id='.$this->customer->id.'&unit_id='.$this->unit->id.'&status=draft&type=sale')->assertJsonPath('meta.total',22);
        $this->getJson('/api/contracts?search=C-000001')->assertJsonCount(1,'data');
        $this->getJson('/api/contracts?search='.urlencode('أحمد'))->assertJsonPath('meta.total',22);
        $this->getJson('/api/contracts?status=cancelled')->assertJsonCount(0,'data');
        $this->getJson('/api/contracts?search='.urlencode('١٠١'))->assertJsonPath('meta.total',22);
    }
}
