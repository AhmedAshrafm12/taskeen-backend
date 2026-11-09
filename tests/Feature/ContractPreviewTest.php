<?php

namespace Tests\Feature;

use App\Models\Building;
use App\Models\Contract;
use App\Models\Customer;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ContractPreviewTest extends TestCase
{
    use RefreshDatabase;

    private Unit $unit;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->unit = Unit::create(['building_id' => Building::create(['name' => 'برج الاختبار', 'address' => 'القاهرة'])->id, 'number' => '١٠١']);
        $this->customer = Customer::create(['name' => 'عميل تجريبي', 'phone' => '01012345678']);
    }

    private function owner(): void
    {
        Sanctum::actingAs(User::factory()->create());
    }

    private function sale(array $extra = []): array
    {
        return array_merge(['request_key' => (string) Str::uuid(), 'unit_id' => $this->unit->id, 'customer_id' => $this->customer->id, 'type' => 'sale', 'sale_mode' => 'cash', 'status' => 'active', 'start_date' => '2026-11-01', 'amount' => '999999999999.99'], $extra);
    }

    private function rental(array $extra = []): array
    {
        $input = $this->sale(['type' => 'rental', 'end_date' => '2026-11-15', 'amount' => '100.01', 'security_deposit' => '40.01', 'frequency' => 'monthly']);
        unset($input['sale_mode']);

        return array_merge($input, $extra);
    }

    private function assertNoFinancialWrites(int $contracts = 0, int $schedules = 0, int $dues = 0, int $events = 0): void
    {
        foreach (['contracts' => $contracts, 'schedules' => $schedules, 'dues' => $dues, 'contract_events' => $events, 'payments' => 0, 'payment_allocations' => 0, 'payment_events' => 0] as $table => $count) {
            $this->assertDatabaseCount($table, $count);
        }
    }

    private function assertMatchesSavedSchedule(array $input, array $rows): void
    {
        $id = $this->postJson('/api/contracts', $input)->assertCreated()->json('data.id');
        $saved = Contract::findOrFail($id)->dues()->orderBy('sequence')->get()->map(fn ($due) => [
            'kind' => $due->kind, 'label' => $due->label, 'due_date' => $due->due_date->toDateString(), 'amount' => $due->amount,
            'period_start' => $due->period_start?->toDateString(), 'period_end' => $due->period_end?->toDateString(),
        ])->all();
        $this->assertSame($rows, $saved);
        $this->postJson('/api/contracts', $input)->assertOk()->assertJsonPath('data.id', $id);
        $this->assertDatabaseCount('contracts', 1);
    }

    public function test_preview_requires_authentication_and_valid_terms(): void
    {
        $this->postJson('/api/contracts/preview', $this->sale())->assertUnauthorized();
        $this->owner();
        $this->postJson('/api/contracts/preview', $this->sale(['amount' => '1.001']))->assertUnprocessable()->assertJsonValidationErrors('amount');
        $this->postJson('/api/contracts/preview', $this->rental(['end_date' => '2026-10-31']))->assertUnprocessable()->assertJsonValidationErrors('end_date');
        $this->postJson('/api/contracts/preview', $this->sale(['sale_mode' => 'installments', 'frequency' => 'monthly', 'installments_count' => 2, 'first_due_date' => '2026-11-01', 'down_payment' => '0.02', 'amount' => '0.03']))->assertUnprocessable()->assertJsonValidationErrors('installments_count');
        $this->assertNoFinancialWrites();
    }

    public function test_cash_preview_is_read_only_repeatable_and_matches_exact_saved_schedule(): void
    {
        $this->owner();
        $input = $this->sale();
        $response = $this->postJson('/api/contracts/preview', $input)->assertOk()->assertJsonCount(1, 'data.rows')->assertJsonPath('data.principal_total', '999999999999.99')->assertJsonPath('data.deposit_total', '0.00');
        $this->postJson('/api/contracts/preview', $input)->assertExactJson($response->json());
        $this->assertNoFinancialWrites();
        $this->assertMatchesSavedSchedule($input, $response->json('data.rows'));
    }

    public function test_installment_preview_keeps_cent_remainders_and_month_end_anchors(): void
    {
        $this->owner();
        $input = $this->sale(['amount' => '100.01', 'sale_mode' => 'installments', 'down_payment' => '10.01', 'frequency' => 'monthly', 'installments_count' => 7, 'start_date' => '2024-01-31', 'first_due_date' => '2024-01-31']);
        $preview = $this->postJson('/api/contracts/preview', $input)->assertOk()->assertJsonCount(8, 'data.rows')
            ->assertJsonPath('data.principal_total', '100.01')->assertJsonPath('data.rows.1.amount', '12.86')->assertJsonPath('data.rows.2.due_date', '2024-02-29')->assertJsonPath('data.rows.3.due_date', '2024-03-31')->assertJsonPath('data.rows.7.amount', '12.85');
        $this->assertNoFinancialWrites();
        $this->assertMatchesSavedSchedule($input, $preview->json('data.rows'));
    }

    public function test_rental_preview_prorates_and_keeps_deposit_separate_without_creating_a_draft(): void
    {
        $this->owner();
        $input = $this->rental();
        $preview = $this->postJson('/api/contracts/preview', $input)->assertOk()->assertJsonCount(2, 'data.rows')->assertJsonPath('data.principal_total', '50.01')->assertJsonPath('data.deposit_total', '40.01')
            ->assertJsonPath('data.rows.0.kind', 'security_deposit')->assertJsonPath('data.rows.1.period_start', '2026-11-01')->assertJsonPath('data.rows.1.period_end', '2026-11-15');
        $this->assertNoFinancialWrites();
        $this->assertMatchesSavedSchedule($input, $preview->json('data.rows'));
    }

    public function test_preview_detects_conflicts_and_save_rechecks_after_a_successful_preview(): void
    {
        $this->owner();
        $input = $this->rental();
        $this->postJson('/api/contracts/preview', $input)->assertOk();
        $this->assertNoFinancialWrites();
        $this->postJson('/api/contracts', $this->rental())->assertCreated();
        $this->postJson('/api/contracts', $input)->assertUnprocessable()->assertJsonValidationErrors('start_date');
        $this->postJson('/api/contracts/preview', $input)->assertUnprocessable()->assertJsonValidationErrors('start_date');
        $this->postJson('/api/contracts/preview', $this->sale())->assertUnprocessable()->assertJsonValidationErrors('start_date');
        $this->assertNoFinancialWrites(1, 1, 2, 1);
    }

    public function test_preview_validates_renewal_without_writing_successor_or_history(): void
    {
        $this->owner();
        $id = $this->postJson('/api/contracts', $this->rental())->assertCreated()->json('data.id');
        $renewal = $this->rental(['renewed_from_id' => $id, 'start_date' => '2026-11-16', 'end_date' => '2026-12-15']);
        $this->postJson('/api/contracts/preview', $renewal)->assertOk()->assertJsonPath('data.principal_total', '100.01');
        $this->postJson('/api/contracts/preview', array_merge($renewal, ['start_date' => '2026-11-17']))->assertUnprocessable()->assertJsonValidationErrors('start_date');
        $this->assertNoFinancialWrites(1, 1, 2, 1);
    }

    public function test_draft_preview_is_hypothetical_and_cannot_bypass_activation_conflicts(): void
    {
        $this->owner();
        $id = $this->postJson('/api/contracts', $this->sale())->assertCreated()->json('data.id');
        $input = $this->rental(['status' => 'draft']);
        $this->postJson('/api/contracts/preview', $input)->assertOk()->assertJsonPath('data.deposit_total', '40.01');
        $this->assertNoFinancialWrites(1, 1, 1, 1);
        $this->postJson('/api/contracts/preview', array_merge($input, ['status' => 'active']))->assertUnprocessable()->assertJsonValidationErrors('unit_id');
        $draft = $this->postJson('/api/contracts', $input)->assertCreated()->json('data.id');
        $this->assertDatabaseCount('dues', 1);
        $this->postJson('/api/contracts/'.$draft.'/transition', ['action' => 'activate', 'reason' => 'اختبار الحماية', 'request_key' => (string) Str::uuid()])->assertUnprocessable();
        $this->assertSame('active', Contract::findOrFail($id)->status);
    }
}
