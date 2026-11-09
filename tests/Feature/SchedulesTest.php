<?php

namespace Tests\Feature;

use App\Models\Building;
use App\Models\Contract;
use App\Models\Customer;
use App\Models\Due;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\Unit;
use App\Models\User;
use App\Support\Money;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SchedulesTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Unit $unit;

    private Unit $rentUnit;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-11-08 12:00:00', 'Africa/Cairo'));
        $this->user = User::factory()->create();
        $building = Building::create(['name' => 'برج النيل', 'address' => 'القاهرة']);
        $this->unit = Unit::create(['building_id' => $building->id, 'number' => '١٠١']);
        $this->rentUnit = Unit::create(['building_id' => $building->id, 'number' => '١٠٢']);
        $this->customer = Customer::create(['name' => 'أحمد محمد', 'phone' => '01012345678']);
    }

    private function owner(): void
    {
        Sanctum::actingAs($this->user);
    }

    private function sale(array $overrides = []): array
    {
        return array_merge(['unit_id' => $this->unit->id, 'customer_id' => $this->customer->id, 'type' => 'sale', 'sale_mode' => 'cash', 'status' => 'active', 'start_date' => '2026-11-08', 'amount' => '100.00', 'request_key' => (string) Str::uuid()], $overrides);
    }

    private function rent(array $overrides = []): array
    {
        return array_merge(['unit_id' => $this->unit->id, 'customer_id' => $this->customer->id, 'type' => 'rental', 'status' => 'active', 'start_date' => '2026-11-01', 'end_date' => '2026-11-30', 'amount' => '100.00', 'security_deposit' => '200.00', 'frequency' => 'monthly', 'request_key' => (string) Str::uuid()], $overrides);
    }

    private function create(array $input): Contract
    {
        return Contract::findOrFail($this->postJson('/api/contracts', $input)->assertCreated()->json('data.id'));
    }

    private function installments(array $overrides = []): array
    {
        return $this->sale(array_merge(['sale_mode' => 'installments', 'down_payment' => '10.00', 'frequency' => 'monthly', 'installments_count' => 3, 'first_due_date' => '2026-11-30'], $overrides));
    }

    private function change(Contract $c, string $action, array $overrides = [])
    {
        return $this->postJson('/api/contracts/'.$c->id.'/transition', array_merge(['action' => $action, 'reason' => 'تأكيد المالك', 'request_key' => (string) Str::uuid()], $overrides));
    }

    private function replace(Contract $c, array $overrides = [])
    {
        return $this->postJson('/api/contracts/'.$c->id.'/schedule', array_merge(['mode' => 'automatic', 'expected_version' => 1, 'reason' => 'مراجعة الجدول', 'request_key' => (string) Str::uuid()], $overrides));
    }

    private function pay(Due $due, string $amount): Payment
    {
        $p = Payment::create(['contract_id' => $due->contract_id, 'user_id' => $this->user->id, 'amount' => $amount, 'paid_on' => '2026-11-08', 'request_key' => (string) Str::uuid(), 'request_hash' => str_repeat('a', 64)]);
        PaymentAllocation::create(['payment_id' => $p->id, 'due_id' => $due->id, 'amount' => $amount]);

        return $p;
    }

    public function test_schedule_endpoints_are_protected(): void
    {
        $this->getJson('/api/dues')->assertUnauthorized();
        $this->getJson('/api/dues/by-contract')->assertUnauthorized();
        $this->getJson('/api/contracts/1/schedule')->assertUnauthorized();
        $this->postJson('/api/contracts/1/schedule')->assertUnauthorized();
        $this->postJson('/api/contracts/1/schedule/preview')->assertUnauthorized();
    }

    public function test_dues_by_contract_groups_open_balances_and_next_due_date(): void
    {
        $this->owner();
        $sale = $this->create($this->installments([
            'start_date' => '2026-09-01', 'amount' => '100.00', 'down_payment' => '10.00',
            'installments_count' => 3, 'first_due_date' => '2026-09-30',
        ]));
        $this->create($this->rent(['unit_id' => $this->rentUnit->id, 'security_deposit' => '50.00']));
        $this->getJson('/api/dues/by-contract?status=open')
            ->assertOk()
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('data.0.principal.remaining', '100.00')
            ->assertJsonPath('data.0.principal.overdue', '70.00')
            ->assertJsonPath('data.0.open_dues_count', 4)
            ->assertJsonPath('data.0.overdue_dues_count', 3)
            ->assertJsonPath('data.0.next_due_date', '2026-09-01');
        $this->getJson('/api/dues/by-contract?status=overdue')->assertJsonPath('meta.total', 2);
        $down = $sale->dues()->where('kind', 'down_payment')->firstOrFail();
        $this->pay($down, '10.00');
        foreach ($sale->dues()->where('kind', 'sale_installment')->orderBy('sequence')->get() as $due) {
            $this->pay($due, $due->amount);
        }
        $this->getJson('/api/dues/by-contract?status=settled')
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.contract_id', $sale->id)
            ->assertJsonPath('data.0.principal.remaining', '0.00')
            ->assertJsonPath('data.0.progress_percent', 100);
    }

    public function test_installment_rounding_and_month_end_anchor_preserve_exact_total(): void
    {
        $this->owner();
        $c = $this->create($this->installments(['start_date' => '2026-01-01', 'first_due_date' => '2026-01-31', 'amount' => '100.01']));
        $dues = $c->dues()->orderBy('sequence')->get();
        $this->assertSame(['10.00', '30.01', '30.00', '30.00'], $dues->pluck('amount')->all());
        $this->assertSame(['2026-01-01', '2026-01-31', '2026-02-28', '2026-03-31'], $dues->map(fn ($d) => $d->due_date->toDateString())->all());
        $this->assertSame(10001, $dues->sum(fn ($d) => Money::cents($d->amount)));
        $this->getJson('/api/dues?contract_id='.$c->id)->assertOk()->assertJsonPath('summary.principal.scheduled', '100.01')->assertJsonPath('summary.principal.paid', '0.00')->assertJsonPath('summary.principal.overdue', '100.01');
    }

    public function test_cash_due_today_and_retried_creation_do_not_duplicate_dues(): void
    {
        $this->owner();
        $input = $this->sale();
        $c = $this->create($input);
        $this->postJson('/api/contracts', $input)->assertOk();
        $this->assertDatabaseCount('dues', 1);
        $this->assertDatabaseCount('schedules', 1);
        $this->getJson('/api/dues?contract_id='.$c->id)->assertJsonPath('data.0.status', 'today')->assertJsonPath('summary.principal.due_now', '100.00')->assertJsonPath('summary.principal.overdue', '0.00');
        $this->travelTo(Carbon::parse('2026-11-09 12:00:00', 'Africa/Cairo'));
        $this->getJson('/api/dues?status=overdue')->assertJsonCount(1, 'data');
    }

    public function test_monthly_rental_final_proration_and_deposit_separation(): void
    {
        $this->owner();
        $c = $this->create($this->rent(['start_date' => '2026-01-31', 'end_date' => '2026-04-15']));
        $r = $c->dues()->where('kind', 'rent')->orderBy('sequence')->get();
        $this->assertSame(['100.00', '100.00', '53.33'], $r->pluck('amount')->all());
        $this->assertSame(['2026-01-31', '2026-02-28', '2026-03-31'], $r->map(fn ($d) => $d->due_date->toDateString())->all());
        $this->getJson('/api/dues?contract_id='.$c->id)->assertJsonPath('summary.principal.scheduled', '253.33')->assertJsonPath('summary.deposit.scheduled', '200.00');
    }

    public function test_leap_year_quarterly_and_yearly_anchors(): void
    {
        $this->owner();
        $a = $this->create($this->rent(['start_date' => '2024-01-31', 'end_date' => '2024-03-30', 'security_deposit' => '0']));
        $this->assertSame(['2024-01-31', '2024-02-29'], $a->dues()->orderBy('sequence')->get()->map(fn ($d) => $d->due_date->toDateString())->all());
        $b = $this->create($this->rent(['start_date' => '2025-01-31', 'end_date' => '2025-05-15', 'frequency' => 'quarterly', 'amount' => '300.00', 'security_deposit' => '0']));
        $this->assertSame(['300.00', '52.17'], $b->dues()->orderBy('sequence')->get()->pluck('amount')->all());
        $c = $this->create($this->rent(['start_date' => '2026-02-28', 'end_date' => '2027-03-01', 'frequency' => 'yearly', 'amount' => '1200.00', 'security_deposit' => '0']));
        $this->assertSame(['1200.00', '6.58'], $c->dues()->orderBy('sequence')->get()->pluck('amount')->all());
    }

    public function test_draft_activation_creates_a_schedule_only_once(): void
    {
        $this->owner();
        $c = $this->create($this->sale(['status' => 'draft']));
        $this->assertDatabaseCount('dues', 0);
        $this->getJson('/api/contracts/'.$c->id.'/schedule')->assertJsonPath('data.can_replace', false);
        $key = (string) Str::uuid();
        $this->change($c, 'activate', ['request_key' => $key])->assertOk();
        $this->change($c, 'activate', ['request_key' => $key])->assertOk();
        $this->assertDatabaseCount('dues', 1);
        $this->assertDatabaseCount('schedules', 1);
    }

    public function test_partial_payments_reversals_and_overdue_filters_are_calculated_from_allocations(): void
    {
        $this->owner();
        $c = $this->create($this->sale(['start_date' => '2026-11-01']));
        $due = $c->dues()->firstOrFail();
        $first = $this->pay($due, '30.00');
        $second = $this->pay($due, '20.01');
        $this->getJson('/api/dues?status=partial')->assertJsonPath('data.0.paid', '50.01')->assertJsonPath('data.0.remaining', '49.99')->assertJsonPath('data.0.is_overdue', true)->assertJsonPath('summary.principal.paid', '50.01');
        $this->getJson('/api/dues?status=overdue')->assertJsonCount(1, 'data');
        $second->update(['status' => 'reversed']);
        $this->getJson('/api/dues')->assertJsonPath('data.0.paid', '30.00')->assertJsonPath('data.0.remaining', '70.00');
        $first->update(['status' => 'reversed']);
        $this->pay($due, '100.00');
        $this->getJson('/api/dues?status=paid')->assertJsonPath('data.0.status', 'paid')->assertJsonPath('data.0.is_overdue', false)->assertJsonPath('summary.principal.remaining', '0.00');
        $this->getJson('/api/dues?status=overdue')->assertJsonCount(0, 'data');
    }

    public function test_deposit_allocations_do_not_inflate_rent_collections(): void
    {
        $this->owner();
        $c = $this->create($this->rent());
        $this->pay($c->dues()->where('kind', 'security_deposit')->firstOrFail(), '100.00');
        $this->getJson('/api/dues?customer_id='.$this->customer->id)->assertJsonPath('summary.principal.paid', '0.00')->assertJsonPath('summary.principal.remaining', '100.00')->assertJsonPath('summary.deposit.paid', '100.00')->assertJsonPath('summary.deposit.remaining', '100.00');
    }

    public function test_custom_schedule_preview_and_versioned_replacement_preserve_originals(): void
    {
        $this->owner();
        $c = $this->create($this->installments());
        $entries = [['due_date' => '2026-11-30', 'amount' => '20.00'], ['due_date' => '2026-12-30', 'amount' => '30.00'], ['due_date' => '2027-01-30', 'amount' => '40.00']];
        $this->postJson('/api/contracts/'.$c->id.'/schedule/preview', ['mode' => 'custom', 'entries' => $entries])->assertOk()->assertJsonPath('data.principal_total', '100.00');
        $this->assertDatabaseCount('schedules', 1);
        $this->assertDatabaseCount('dues', 4);
        $key = (string) Str::uuid();
        $input = ['mode' => 'custom', 'entries' => $entries, 'request_key' => $key];
        $this->replace($c, $input)->assertOk()->assertJsonPath('data.version', 2);
        $this->replace($c, $input)->assertOk();
        $this->assertDatabaseCount('schedules', 2);
        $this->assertDatabaseCount('dues', 8);
        $this->assertSame(4, $c->dues()->where('is_void', true)->count());
        $this->getJson('/api/contracts/'.$c->id.'/schedule')->assertJsonPath('data.mode', 'custom')->assertJsonCount(3, 'data.entries')->assertJsonCount(2, 'data.revisions');
        $this->getJson('/api/dues?contract_id='.$c->id)->assertJsonPath('summary.principal.scheduled', '100.00');
        $this->replace($c, ['expected_version' => 1])->assertUnprocessable();
        $this->replace($c, array_merge($input, ['reason' => 'different']))->assertStatus(409);
        $this->assertSame('100.00', $c->fresh()->amount);
    }

    public function test_invalid_custom_totals_counts_dates_and_precision_are_rejected(): void
    {
        $this->owner();
        $c = $this->create($this->installments());
        foreach ([[], [['due_date' => '2026-11-30', 'amount' => '90.00']], [['due_date' => '2026-11-29', 'amount' => '30'], ['due_date' => '2026-12-30', 'amount' => '30'], ['due_date' => '2027-01-30', 'amount' => '30']], [['due_date' => '2026-11-30', 'amount' => '0.001'], ['due_date' => '2026-12-30', 'amount' => '30'], ['due_date' => '2027-01-30', 'amount' => '30']], [['due_date' => '2026-11-30', 'amount' => '31'], ['due_date' => '2026-12-30', 'amount' => '30'], ['due_date' => '2027-01-30', 'amount' => '30']]] as $entries) {
            $this->replace($c, ['mode' => 'custom', 'entries' => $entries])->assertUnprocessable();
        }
        $this->assertDatabaseCount('schedules', 1);
    }

    public function test_reversed_allocations_still_protect_schedule_but_allow_cancel_and_termination(): void
    {
        $this->owner();
        $c = $this->create($this->rent());
        $p = $this->pay($c->dues()->where('kind', 'rent')->firstOrFail(), '10.00');
        $this->change($c, 'cancel')->assertUnprocessable();
        $p->update(['status' => 'reversed']);
        $this->replace($c)->assertUnprocessable()->assertJsonValidationErrors('schedule');
        $this->getJson('/api/contracts/'.$c->id.'/schedule')->assertJsonPath('data.has_allocations', true)->assertJsonPath('data.can_replace', false);
        $this->change($c, 'cancel')->assertOk()->assertJsonPath('data.status', 'cancelled');
        $this->getJson('/api/dues?contract_id='.$c->id)->assertJsonPath('summary.principal.remaining', '0.00');
    }

    public function test_termination_keep_works_with_reversed_allocation_history(): void
    {
        $this->owner();
        $c = $this->create($this->rent(['end_date' => '2027-01-31']));
        $p = $this->pay($c->dues()->where('kind', 'rent')->orderBy('due_date')->firstOrFail(), '10.00');
        $p->update(['status' => 'reversed']);
        $this->change($c, 'terminate', ['effective_date' => '2026-11-07', 'settlement_mode' => 'keep'])
            ->assertOk()
            ->assertJsonPath('data.status', 'ended')
            ->assertJsonPath('data.effective_end_date', '2026-11-07');
        $this->getJson('/api/dues?contract_id='.$c->id)->assertJsonPath('summary.principal.remaining', '100.00');
    }

    public function test_cancellation_voids_unpaid_dues_without_erasing_history(): void
    {
        $this->owner();
        $c = $this->create($this->sale());
        $this->change($c, 'cancel')->assertOk();
        $this->assertDatabaseCount('dues', 1);
        $this->getJson('/api/dues')->assertJsonCount(0, 'data')->assertJsonPath('summary.principal.remaining', '0.00');
        $this->getJson('/api/dues?status=voided')->assertJsonCount(1, 'data')->assertJsonPath('data.0.remaining', '0.00');
    }

    public function test_termination_preview_and_rebuild_prorate_without_changing_original_records(): void
    {
        $this->owner();
        $c = $this->create($this->rent());
        $original = $c->dues()->where('kind', 'rent')->firstOrFail();
        $this->postJson('/api/contracts/'.$c->id.'/schedule/preview', ['mode' => 'automatic', 'termination_date' => '2026-11-07'])
            ->assertOk()
            ->assertJsonPath('data.principal_total', '23.33')
            ->assertJsonPath('data.deposit_total', '200.00')
            ->assertJsonPath('data.settlement.after_keep_principal', '23.33')
            ->assertJsonPath('data.settlement.principal_remaining', '100.00');
        $this->assertDatabaseCount('schedules', 1);
        $this->assertSame('2026-11-30', $c->fresh()->effective_end_date->toDateString());
        $this->change($c, 'terminate', ['effective_date' => '2026-11-07'])->assertOk();
        $this->assertSame('100.00', $original->fresh()->amount);
        $this->assertTrue($original->fresh()->is_void);
        $this->assertDatabaseCount('schedules', 2);
        $this->getJson('/api/dues?contract_id='.$c->id)->assertJsonPath('summary.principal.remaining', '23.33')->assertJsonPath('summary.deposit.remaining', '200.00');
    }

    public function test_custom_rental_schedule_requires_review_before_termination(): void
    {
        $this->owner();
        $c = $this->create($this->rent());
        $this->replace($c, ['mode' => 'custom', 'entries' => [['due_date' => '2026-11-01', 'amount' => '100.00']]])->assertOk();
        $this->change($c, 'terminate', ['effective_date' => '2026-11-07'])->assertUnprocessable();
        $this->replace($c, ['expected_version' => 2])->assertOk();
        $this->change($c, 'terminate', ['effective_date' => '2026-11-07'])->assertOk();
    }

    public function test_termination_can_waive_outstanding_principal_while_keeping_deposit(): void
    {
        $this->owner();
        $c = $this->create($this->rent());
        $this->change($c, 'terminate', ['effective_date' => '2026-11-07', 'settlement_mode' => 'waive'])->assertOk();
        $this->getJson('/api/dues?contract_id='.$c->id)
            ->assertJsonPath('summary.principal.remaining', '0.00')
            ->assertJsonPath('summary.deposit.remaining', '200.00');
    }

    public function test_filtered_summary_covers_all_pages_and_phone_search(): void
    {
        $this->owner();
        $c = $this->create($this->installments(['amount' => '300.00', 'down_payment' => '0', 'installments_count' => 30, 'start_date' => '2026-01-01', 'first_due_date' => '2026-01-01']));
        $this->getJson('/api/dues?contract_id='.$c->id)->assertJsonCount(20, 'data')->assertJsonPath('meta.total', 30)->assertJsonPath('summary.principal.scheduled', '300.00');
        $this->getJson('/api/dues?contract_id='.$c->id.'&page=2')->assertJsonCount(10, 'data')->assertJsonPath('summary.principal.scheduled', '300.00');
        $this->getJson('/api/dues?due_from=2026-01-01&due_to=2026-02-28')->assertJsonCount(2, 'data')->assertJsonPath('summary.principal.scheduled', '20.00');
        $this->getJson('/api/dues?due_to=2026-02-28')->assertOk()->assertJsonCount(2, 'data');
        $this->getJson('/api/dues?search='.urlencode('٠١٠١٢٣٤٥٦٧٨'))->assertJsonPath('meta.total', 30);
        $this->getJson('/api/dues?search=---')->assertJsonCount(0, 'data');
        $this->getJson('/api/dues?building_id='.$this->unit->building_id.'&kind=sale_installment')->assertJsonPath('meta.total', 30);
        $this->getJson('/api/dues?due_from=2026-12-01&due_to=2026-01-01')->assertUnprocessable();
    }

    public function test_legacy_backfill_is_idempotent_and_skips_drafts(): void
    {
        $this->owner();
        $legacy = Contract::create(array_merge($this->sale(), ['request_hash' => str_repeat('a', 64), 'activated_at' => now()]));
        Contract::create(array_merge($this->sale(['status' => 'draft']), ['request_hash' => str_repeat('b', 64)]));
        $this->artisan('app:generate-schedules')->assertSuccessful();
        $this->artisan('app:generate-schedules')->assertSuccessful();
        $this->assertDatabaseCount('schedules',1);
        $this->assertDatabaseCount('dues',1);
        $this->assertSame($legacy->id,Due::sole()->contract_id);
    }

    public function test_large_decimal_amount_retains_the_last_cent_after_partial_allocation(): void
    {
        $this->owner();
        $c = $this->create($this->sale(['amount' => '999999999999.99']));
        $this->pay($c->dues()->firstOrFail(),'999999999999.98');
        $this->getJson('/api/dues?status=partial')->assertJsonPath('data.0.remaining','0.01')->assertJsonPath('summary.principal.remaining','0.01');
    }
}
