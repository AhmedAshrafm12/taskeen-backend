<?php

namespace Tests\Feature;

use App\Models\Building;
use App\Models\Contract;
use App\Models\Customer;
use App\Models\Due;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\Schedule;
use App\Models\Unit;
use App\Models\User;
use App\Services\ContractService;
use App\Services\PaymentService;
use App\Support\Money;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ReportsTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Customer $customer;

    private int $number = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-11-08 12:00:00', 'Africa/Cairo'));
        $this->user = User::factory()->create();
        $this->customer = Customer::create(['name' => 'أحمد محمد', 'phone' => '01012345678']);
    }

    private function owner(): void
    {
        Sanctum::actingAs($this->user);
    }

    private function building(string $name = 'برج النيل'): Building
    {
        return Building::create(['name' => $name, 'address' => 'القاهرة']);
    }

    private function contract(Building $b, array $extra = []): Contract
    {
        $unit = Unit::create(['building_id' => $b->id, 'number' => (string) ++$this->number]);
        $input = array_merge(['unit_id' => $unit->id, 'customer_id' => $this->customer->id, 'type' => 'sale', 'sale_mode' => 'cash', 'status' => 'active', 'start_date' => '2026-11-01', 'amount' => '100.00', 'request_key' => (string) Str::uuid()], $extra);
        if ($input['type'] === 'rental') {
            unset($input['sale_mode']);
        }

        return app(ContractService::class)->create($input, $this->user->id);
    }

    private function payment(Contract $c, array $amounts, string $date = '2026-11-08', ?Payment $source = null): Payment
    {
        $service = app(PaymentService::class);
        $ctx = $service->context($c->fresh(), $source?->fresh());
        $rows = collect($ctx['dues'])->keyBy('id');
        $total = 0;
        $lines = [];
        foreach ($amounts as $id => $amount) {
            $lines[] = ['due_id' => $id, 'amount' => $amount, 'expected_remaining' => $rows[$id]['remaining']];
            $total += Money::cents($amount);
        }

        return $service->record(['contract_id' => $c->id, 'amount' => Money::decimal($total), 'paid_on' => $date, 'method' => 'cash', 'expected_schedule_version' => $ctx['schedule_version'], 'expected_principal_remaining' => $ctx['remaining']['principal'], 'expected_deposit_remaining' => $ctx['remaining']['deposit'], 'allocations' => $lines, 'reason' => 'بيانات اختبار التقرير', 'request_key' => (string) Str::uuid()], $this->user->id, $source);
    }

    private function fixture(): array
    {
        $a = $this->building();
        $b = $this->building('برج البحر');
        $rent = $this->contract($a, ['type' => 'rental', 'end_date' => '2026-11-30', 'frequency' => 'monthly', 'security_deposit' => '50.00']);
        $sale = $this->contract($a);
        $this->contract($a, ['status' => 'draft']);
        $this->contract($a, ['type' => 'rental', 'start_date' => '2026-12-01', 'end_date' => '2026-12-31', 'frequency' => 'monthly']);
        $this->contract($a, ['type' => 'rental', 'start_date' => '2026-10-01', 'end_date' => '2026-10-31', 'frequency' => 'monthly']);
        $bSale = $this->contract($b);
        $cancelled = $this->contract($b);
        app(ContractService::class)->transition($cancelled, ['action' => 'cancel', 'reason' => 'إلغاء اختبار', 'request_key' => (string) Str::uuid()], $this->user->id);
        $this->payment($rent, [$rent->dues()->where('kind', 'rent')->first()->id => '40.00', $rent->dues()->where('kind', 'security_deposit')->first()->id => '10.00']);
        $this->payment($sale, [$sale->dues()->first()->id => '20.00']);
        $this->payment($bSale, [$bSale->dues()->first()->id => '10.00'], '2026-10-31');
        $reverse = $this->payment($bSale, [$bSale->dues()->first()->id => '5.00']);
        app(PaymentService::class)->reverse($reverse, ['reason' => 'عكس اختبار', 'request_key' => (string) Str::uuid()], $this->user->id);
        $b->update(['is_active' => false]);

        return compact('a', 'b', 'rent', 'sale', 'bSale');
    }

    public function test_dashboard_reports_and_print_require_authentication(): void
    {
        $this->getJson('/api/dashboard')->assertUnauthorized();
        $this->getJson('/api/reports')->assertUnauthorized();
        $this->getJson('/api/reports/print')->assertUnauthorized();
    }

    public function test_empty_reports_return_zero_strings_without_writing_any_rows(): void
    {
        $this->owner();
        $this->getJson('/api/dashboard')->assertOk()->assertJsonPath('data.counts.buildings', 0)->assertJsonPath('data.counts.units', 0)->assertJsonPath('data.collections.collected_total', '0.00')->assertJsonCount(0, 'data.overdue');
        $this->getJson('/api/reports')->assertOk()->assertJsonCount(0, 'data')->assertJsonPath('meta.total', 0)->assertJsonPath('report.filters.date_from', '2026-11-01')->assertJsonPath('report.filters.date_to', '2026-11-08')->assertJsonPath('report.summary.contracts.signed_sales_value', '0.00');
        $this->getJson('/api/reports/print')->assertOk()->assertJsonPath('total', 0)->assertJsonCount(0, 'data');
        $this->assertDatabaseCount('contracts', 0);
        $this->assertDatabaseCount('dues', 0);
        $this->assertDatabaseCount('payments', 0);
    }

    public function test_dashboard_live_counts_urgent_dues_and_month_collections_are_consistent(): void
    {
        $f = $this->fixture();
        $this->owner();
        $this->getJson('/api/dashboard')->assertOk()->assertJsonPath('data.counts.buildings', 2)->assertJsonPath('data.counts.active_buildings', 1)
            ->assertJsonPath('data.counts.units', 7)->assertJsonPath('data.counts.available_units', 4)->assertJsonPath('data.counts.rented_units', 1)->assertJsonPath('data.counts.sold_units', 2)
            ->assertJsonPath('data.counts.active_contracts', 4)->assertJsonPath('data.counts.overdue_dues', 5)->assertJsonPath('data.counts.upcoming_dues', 1)
            ->assertJsonPath('data.collections.principal_collected', '60.00')->assertJsonPath('data.collections.deposit_collected', '10.00')->assertJsonPath('data.collections.reversed_total', '5.00')
            ->assertJsonPath('data.balances.principal.overdue', '330.00')->assertJsonPath('data.balances.deposit.overdue', '40.00')->assertJsonCount(3, 'data.recent_payments');
        $this->getJson('/api/dashboard?building_id='.$f['a']->id)->assertJsonPath('data.counts.units', 5)->assertJsonPath('data.counts.active_contracts', 3)->assertJsonPath('data.counts.overdue_dues', 4);
        $this->getJson('/api/dashboard?building_id='.$f['b']->id)->assertJsonPath('data.counts.active_buildings', 0)->assertJsonPath('data.counts.units', 2)->assertJsonPath('data.collections.collected_total', '0.00')->assertJsonPath('data.collections.reversed_total', '5.00')->assertJsonPath('data.balances.principal.remaining', '90.00');
    }

    public function test_report_date_bases_separate_collections_dues_and_contract_agreements(): void
    {
        $this->fixture();
        $this->owner();
        $this->getJson('/api/reports?type=collections&date_from=2026-11-01&date_to=2026-11-08')->assertJsonCount(3, 'data')
            ->assertJsonPath('report.summary.collections.collected_total', '70.00')->assertJsonPath('report.summary.collections.principal_collected', '60.00')->assertJsonPath('report.summary.collections.deposit_collected', '10.00')
            ->assertJsonPath('report.summary.dues.principal.scheduled', '300.00')->assertJsonPath('report.summary.dues.principal.paid', '70.00')->assertJsonPath('report.summary.dues.principal.remaining', '230.00')
            ->assertJsonPath('report.summary.contracts.all_count', 5)->assertJsonPath('report.summary.contracts.signed_count', 3)->assertJsonPath('report.summary.contracts.draft_count', 1)->assertJsonPath('report.summary.contracts.cancelled_count', 1)
            ->assertJsonPath('report.summary.contracts.signed_sales_value', '200.00')->assertJsonPath('report.summary.contracts.rental_scheduled_value', '100.00');
        $this->getJson('/api/reports?type=dues')->assertJsonCount(4, 'data');
        $this->getJson('/api/reports?type=contracts')->assertJsonCount(5, 'data');
    }

    public function test_building_filters_apply_to_every_summary_and_each_detail_type(): void
    {
        $f = $this->fixture();
        $this->owner();
        foreach (['collections', 'dues', 'contracts'] as $type) {
            $r = $this->getJson('/api/reports?type='.$type.'&building_id='.$f['b']->id)->assertOk()->assertJsonPath('report.filters.building_name', 'برج البحر')
                ->assertJsonPath('report.summary.collections.collected_total', '0.00')->assertJsonPath('report.summary.collections.reversed_total', '5.00')
                ->assertJsonPath('report.summary.dues.principal.scheduled', '100.00')->assertJsonPath('report.summary.dues.principal.paid', '10.00')->assertJsonPath('report.summary.contracts.signed_sales_value', '100.00');
            foreach ($r->json('data') as $row) {
                $this->assertSame($f['b']->id, $row['building_id']);
            }
        }
    }

    public function test_dashboard_drilldown_filters_match_corresponding_lists(): void
    {
        $f = $this->fixture();
        $this->owner();
        $id = $f['a']->id;
        $this->getJson('/api/contracts?building_id='.$id.'&status=active')->assertJsonPath('meta.total', 3);
        $this->getJson('/api/units?building_id='.$id.'&status=available')->assertJsonPath('meta.total', 3);
        $this->getJson('/api/dues?building_id='.$id.'&status=overdue')->assertJsonPath('meta.total', 4);
        $this->getJson('/api/payments?building_id='.$id.'&status=posted&paid_from=2026-11-01&paid_to=2026-11-08')->assertJsonPath('summary.collected_total', '70.00');
    }

    public function test_historical_period_is_restated_using_current_correction_and_reversal_state(): void
    {
        $this->owner();
        $c = $this->contract($this->building(), ['start_date' => '2026-10-01']);
        $due = $c->dues()->firstOrFail();
        $old = $this->payment($c, [$due->id => '40.00'], '2026-10-15');
        $this->payment($c, [$due->id => '30.00'], '2026-10-15', $old);
        $this->getJson('/api/reports?date_from=2026-10-01&date_to=2026-10-31')->assertJsonPath('report.as_of', '2026-11-08')->assertJsonPath('report.summary.collections.collected_total', '30.00')->assertJsonPath('report.summary.collections.reversed_total', '40.00')->assertJsonPath('report.summary.dues.principal.remaining', '70.00');
    }

    public function test_cancelled_or_other_non_posted_payments_never_inflate_collections(): void
    {
        $this->owner();
        $c = $this->contract($this->building());
        $due = $c->dues()->firstOrFail();
        $p = $this->payment($c, [$due->id => '10.00']);
        $p->update(['status' => 'cancelled']);
        $this->getJson('/api/dashboard')->assertJsonPath('data.collections.collected_total', '0.00')->assertJsonCount(0, 'data.recent_payments');
        $this->getJson('/api/reports')->assertJsonPath('report.summary.collections.collected_total', '0.00')->assertJsonPath('report.summary.collections.reversed_total', '10.00')->assertJsonPath('report.summary.dues.principal.paid', '0.00');
    }

    public function test_paginated_totals_cover_all_rows_and_print_includes_every_matching_row(): void
    {
        $this->owner();
        $this->contract($this->building(), ['sale_mode' => 'installments', 'amount' => '25.00', 'down_payment' => '0', 'installments_count' => 25, 'frequency' => 'monthly', 'first_due_date' => '2026-11-01']);
        $q = 'type=dues&date_from=2026-01-01&date_to=2030-12-31';
        $this->getJson('/api/reports?'.$q)->assertJsonCount(20, 'data')->assertJsonPath('meta.total', 25)->assertJsonPath('report.summary.dues.principal.scheduled', '25.00');
        $this->getJson('/api/reports?'.$q.'&page=2')->assertJsonCount(5, 'data')->assertJsonPath('report.summary.dues.principal.scheduled', '25.00');
        $this->getJson('/api/reports/print?'.$q)->assertJsonCount(25, 'data')->assertJsonPath('total', 25)->assertJsonPath('report.summary.dues.principal.scheduled', '25.00');
    }

    public function test_print_limit_rejects_oversized_output_instead_of_silently_truncating(): void
    {
        $this->owner();
        $b = $this->building();
        for ($i = 0; $i < 9; $i++) {
            $this->contract($b, ['sale_mode' => 'installments', 'amount' => '6.00', 'down_payment' => '0', 'installments_count' => 600, 'frequency' => 'monthly', 'first_due_date' => '2026-11-01']);
        }
        $this->getJson('/api/reports/print?type=dues&date_from=2026-01-01&date_to=2100-12-31')->assertUnprocessable()->assertJsonValidationErrors('print');
        $this->getJson('/api/reports/print?type=dues&date_from=2026-11-01&date_to=2026-11-08')->assertOk()->assertJsonCount(9, 'data');
    }

    public function test_invalid_date_types_and_buildings_are_rejected(): void
    {
        $this->owner();
        foreach (['date_from=2026-12-01&date_to=2026-01-01', 'type=bad', 'building_id=99999', 'date_from=not-a-date', 'date_from=', 'page=-1'] as $query) {
            $this->getJson('/api/reports?'.$query)->assertUnprocessable();
        }
        $this->getJson('/api/dashboard?building_id=99999')->assertUnprocessable();
    }

    public function test_reports_preserve_exact_large_amounts_and_the_last_cent(): void
    {
        $this->owner();
        $c = $this->contract($this->building(), ['amount' => '999999999999.99']);
        $due = $c->dues()->firstOrFail();
        $this->payment($c, [$due->id => '999999999999.98']);
        $this->getJson('/api/reports')->assertJsonPath('report.summary.collections.collected_total', '999999999999.98')->assertJsonPath('report.summary.dues.principal.remaining', '0.01')->assertJsonPath('report.summary.contracts.signed_sales_value', '999999999999.99');
    }

    public function test_all_reporting_endpoints_are_read_only(): void
    {
        $this->fixture();
        $this->owner();
        $models = [Building::class, Unit::class, Contract::class, Schedule::class, Due::class, Payment::class, PaymentAllocation::class];
        $counts = array_map(fn ($m) => $m::count(),$models);
        $this->getJson('/api/dashboard')->assertOk();
        $this->getJson('/api/reports')->assertOk();
        $this->getJson('/api/reports/print')->assertOk();
        $this->assertSame($counts,array_map(fn ($m) => $m::count(),$models));
    }
}
