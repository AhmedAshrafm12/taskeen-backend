<?php

namespace Tests\Feature;

use App\Models\Building;
use App\Models\Contract;
use App\Models\Customer;
use App\Models\Payment;
use App\Models\Unit;
use App\Models\User;
use App\Services\PaymentService;
use App\Support\Money;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PaymentsTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Unit $unit;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-11-08 12:00:00', 'Africa/Cairo'));
        $this->user = User::factory()->create();
        $b = Building::create(['name' => 'برج النيل', 'address' => 'القاهرة']);
        $this->unit = Unit::create(['building_id' => $b->id, 'number' => '١٠١']);
        $this->customer = Customer::create(['name' => 'أحمد محمد', 'phone' => '01012345678']);
    }

    private function owner(): void
    {
        Sanctum::actingAs($this->user);
    }

    private function contract(array $extra = []): Contract
    {
        $data = array_merge(['unit_id' => $this->unit->id, 'customer_id' => $this->customer->id, 'type' => 'sale', 'sale_mode' => 'cash', 'status' => 'active', 'start_date' => '2026-11-01', 'amount' => '100.00', 'request_key' => (string) Str::uuid()], $extra);

        return Contract::findOrFail($this->postJson('/api/contracts', $data)->assertCreated()->json('data.id'));
    }

    private function input(Contract $c, array $amounts = [], ?int $source = null): array
    {
        $ctx = app(PaymentService::class)->context($c->fresh(), $source ? Payment::findOrFail($source) : null);
        if (! $amounts) {
            $amounts = [$ctx['dues'][0]['id'] => '40.00'];
        }
        $lines = [];
        $total = 0;
        foreach ($amounts as $id => $amount) {
            $row = collect($ctx['dues'])->firstWhere('id', $id);
            $lines[] = ['due_id' => $id, 'amount' => $amount, 'expected_remaining' => $row['remaining'] ?? '100.00'];
            $total += Money::cents($amount);
        }

        return ['contract_id' => $c->id, 'amount' => Money::decimal($total), 'paid_on' => '2026-11-08', 'method' => 'cash', 'expected_schedule_version' => $ctx['schedule_version'], 'expected_principal_remaining' => $ctx['remaining']['principal'], 'expected_deposit_remaining' => $ctx['remaining']['deposit'], 'allocations' => $lines, 'request_key' => (string) Str::uuid()];
    }

    private function pay(Contract $c, array $amounts = [], array $extra = []): Payment
    {
        return Payment::findOrFail($this->postJson('/api/payments', array_merge($this->input($c, $amounts), $extra))->assertCreated()->json('data.id'));
    }

    public function test_payment_endpoints_require_authentication(): void
    {
        $this->getJson('/api/payments')->assertUnauthorized();
        $this->getJson('/api/payments/1')->assertUnauthorized();
        $this->postJson('/api/payments')->assertUnauthorized();
        $this->postJson('/api/payments/preview')->assertUnauthorized();
        $this->getJson('/api/contracts/1/payment-context')->assertUnauthorized();
        $this->getJson('/api/payments/1/receipt')->assertUnauthorized();
        $this->postJson('/api/payments/1/reverse')->assertUnauthorized();
        $this->postJson('/api/payments/1/correct')->assertUnauthorized();
    }

    public function test_preview_is_read_only_and_posting_updates_balances_with_an_immutable_receipt(): void
    {
        $this->owner();
        $c = $this->contract();
        $input = $this->input($c);
        $this->postJson('/api/payments/preview', $input)->assertOk()->assertJsonPath('data.amount', '40.00')->assertJsonPath('data.contract_remaining_after.principal', '60.00');
        $this->assertDatabaseCount('payments', 0);
        $response = $this->postJson('/api/payments', $input)->assertCreated()->assertJsonPath('data.status', 'posted')->assertJsonPath('data.receipt.amount', '40.00')->assertJsonPath('data.receipt.contract.customer.name', 'أحمد محمد')->assertJsonCount(1, 'data.history');
        $id = $response->json('data.id');
        $snapshot = $response->json('data.receipt');
        $this->getJson('/api/dues?contract_id='.$c->id)->assertJsonPath('data.0.paid', '40.00')->assertJsonPath('data.0.remaining', '60.00')->assertJsonPath('data.0.status', 'partial');
        $this->customer->update(['name' => 'اسم جديد']);
        $this->unit->building->update(['name' => 'مبنى جديد']);
        $this->getJson('/api/payments/'.$id.'/receipt')->assertOk()->assertJsonPath('data.receipt', $snapshot)->assertJsonMissingPath('data.request_key')->assertJsonMissingPath('data.request_hash');
    }

    public function test_single_payment_allocates_to_multiple_dues_and_separates_deposit_from_rent(): void
    {
        $this->owner();
        $c = $this->contract(['type' => 'rental', 'sale_mode' => null, 'end_date' => '2026-11-30', 'frequency' => 'monthly', 'security_deposit' => '50.00']);
        $rent = $c->dues()->where('kind', 'rent')->firstOrFail();
        $deposit = $c->dues()->where('kind', 'security_deposit')->firstOrFail();
        $p = $this->pay($c, [$rent->id => '40.00', $deposit->id => '10.00'], ['method' => 'wallet', 'reference' => 'W-123']);
        $this->getJson('/api/payments/'.$p->id)->assertJsonCount(2, 'data.allocations')->assertJsonPath('data.receipt.principal_amount', '40.00')->assertJsonPath('data.receipt.deposit_amount', '10.00');
        $this->getJson('/api/payments')->assertJsonPath('summary.collected_total', '50.00')->assertJsonPath('summary.principal_collected', '40.00')->assertJsonPath('summary.deposit_collected', '10.00');
        $this->getJson('/api/dues?customer_id='.$this->customer->id)->assertJsonPath('summary.principal.remaining', '60.00')->assertJsonPath('summary.deposit.remaining', '40.00');
    }

    public function test_idempotent_recording_replays_receipt_and_rejects_key_reuse_with_different_payload(): void
    {
        $this->owner();
        $c = $this->contract();
        $input = $this->input($c);
        $id = $this->postJson('/api/payments', $input)->assertCreated()->json('data.id');
        $this->postJson('/api/payments', $input)->assertOk()->assertJsonPath('data.id', $id);
        $this->postJson('/api/payments', array_merge($input, ['method' => 'transfer']))->assertStatus(409);
        $this->assertDatabaseCount('payments', 1);
        $this->assertDatabaseCount('payment_allocations', 1);
        $this->assertDatabaseCount('payment_events', 1);
    }

    public function test_overpayment_sum_mismatch_duplicates_and_invalid_fields_are_rejected(): void
    {
        $this->owner();
        $c = $this->contract();
        $input = $this->input($c);
        foreach ([['amount' => '0'], ['amount' => '1.001'], ['paid_on' => '2026-11-09'], ['method' => 'invalid'], ['amount' => '41.00']] as $extra) {
            $this->postJson('/api/payments', array_merge($input, $extra))->assertUnprocessable();
        }
        $over = $this->input($c, [$c->dues()->firstOrFail()->id => '101.00']);
        $this->postJson('/api/payments', $over)->assertUnprocessable();
        $duplicate = $input;
        $duplicate['allocations'][] = $duplicate['allocations'][0];
        $duplicate['amount'] = '80.00';
        $this->postJson('/api/payments', $duplicate)->assertUnprocessable();
        $this->assertDatabaseCount('payments', 0);
    }

    public function test_allocations_cannot_cross_contracts_or_use_voided_dues(): void
    {
        $this->owner();
        $a = $this->contract();
        $u = Unit::create(['building_id' => $this->unit->building_id, 'number' => '102']);
        $b = $this->contract(['unit_id' => $u->id]);
        $input = $this->input($a);
        $input['allocations'][0]['due_id'] = $b->dues()->firstOrFail()->id;
        $this->postJson('/api/payments', $input)->assertUnprocessable();
        $old = $a->dues()->firstOrFail();
        $this->postJson('/api/contracts/'.$a->id.'/schedule', ['mode' => 'automatic', 'reason' => 'مراجعة', 'expected_version' => 1, 'request_key' => (string) Str::uuid()])->assertOk();
        $input = $this->input($a);
        $input['allocations'][0]['due_id'] = $old->id;
        $this->postJson('/api/payments', $input)->assertUnprocessable();
        $this->assertDatabaseCount('payments', 0);
    }

    public function test_stale_review_balances_and_schedule_versions_are_rejected(): void
    {
        $this->owner();
        $c = $this->contract();
        $stale = $this->input($c);
        $this->pay($c, [$c->dues()->firstOrFail()->id => '20.00']);
        $this->postJson('/api/payments', $stale)->assertUnprocessable()->assertJsonValidationErrors('balance');
        $fresh = $this->input($c);
        $fresh['expected_schedule_version'] = 999;
        $this->postJson('/api/payments', $fresh)->assertUnprocessable()->assertJsonValidationErrors('schedule');
        $this->assertDatabaseCount('payments', 1);
    }

    public function test_reversal_preserves_original_receipt_and_restores_due_balances_exactly_once(): void
    {
        $this->owner();
        $c = $this->contract();
        $p = $this->pay($c);
        $snapshot = $p->receipt_snapshot;
        $allocations = $p->allocations()->count();
        $data = ['reason' => 'إدخال بالخطأ', 'request_key' => (string) Str::uuid()];
        $this->postJson('/api/payments/'.$p->id.'/reverse', $data)->assertOk()->assertJsonPath('data.status', 'reversed')->assertJsonPath('data.receipt', $snapshot);
        $this->postJson('/api/payments/'.$p->id.'/reverse', $data)->assertOk();
        $this->postJson('/api/payments/'.$p->id.'/reverse', array_merge($data, ['request_key' => (string) Str::uuid()]))->assertUnprocessable();
        $this->getJson('/api/dues?contract_id='.$c->id)->assertJsonPath('summary.principal.paid', '0.00')->assertJsonPath('summary.principal.remaining', '100.00');
        $this->getJson('/api/payments')->assertJsonPath('summary.collected_total', '0.00')->assertJsonPath('summary.reversed_total', '40.00');
        $this->assertSame($allocations, $p->allocations()->count());
        $this->assertDatabaseCount('payment_events', 2);
    }

    public function test_correction_is_atomic_links_receipts_and_preserves_original_amount_and_allocations(): void
    {
        $this->owner();
        $c = $this->contract();
        $due = $c->dues()->firstOrFail();
        $original = $this->pay($c);
        $snapshot = $original->receipt_snapshot;
        $this->pay($c, [$due->id => '20.00']);
        $input = $this->input($c, [$due->id => '30.00'], $original->id);
        $input['reason'] = 'تصحيح قيمة الدفعة';
        $this->postJson('/api/payments/preview', array_merge($input, ['corrects_payment_id' => $original->id]))->assertOk()->assertJsonPath('data.contract_remaining_after.principal', '50.00');
        $replacement = $this->postJson('/api/payments/'.$original->id.'/correct', $input)->assertCreated()->assertJsonPath('data.corrects_payment_id', $original->id)->json('data.id');
        $this->postJson('/api/payments/'.$original->id.'/correct', $input)->assertOk()->assertJsonPath('data.id', $replacement);
        $this->assertSame('40.00', $original->fresh()->amount);
        $this->assertSame('reversed', $original->fresh()->status);
        $this->assertSame($snapshot, $original->fresh()->receipt_snapshot);
        $this->assertSame('40.00', $original->allocations()->firstOrFail()->amount);
        $this->getJson('/api/payments/'.$original->id)->assertJsonPath('data.replacement_payment_id', $replacement);
        $this->getJson('/api/dues?contract_id='.$c->id)->assertJsonPath('summary.principal.paid', '50.00')->assertJsonPath('summary.principal.remaining', '50.00');
        $this->getJson('/api/payments')->assertJsonPath('summary.collected_total', '50.00')->assertJsonPath('summary.reversed_total', '40.00');
    }

    public function test_failed_correction_rolls_back_even_if_database_error_occurs_after_reversal(): void
    {
        $this->owner();
        $c = $this->contract();
        $due = $c->dues()->firstOrFail();
        $original = $this->pay($c);
        $other = $this->pay($c, [$due->id => '10.00']);
        $collision = (string) Str::uuid();
        $this->postJson('/api/payments/'.$other->id.'/reverse', ['reason' => 'عكس تجريبي', 'request_key' => $collision])->assertOk();
        $input = $this->input($c, [$due->id => '30.00'], $original->id);
        $input['reason'] = 'تصحيح';
        $input['request_key'] = $collision;
        $this->postJson('/api/payments/'.$original->id.'/correct', $input)->assertStatus(409);
        $this->assertSame('posted', $original->fresh()->status);
        $this->assertDatabaseCount('payments', 2);
        $this->assertSame(0, Payment::where('corrects_payment_id', $original->id)->count());
        $this->getJson('/api/dues?contract_id='.$c->id)->assertJsonPath('summary.principal.paid', '40.00')->assertJsonPath('summary.principal.remaining', '60.00');
    }

    public function test_drafts_cancelled_contracts_and_reversed_correction_sources_are_rejected(): void
    {
        $this->owner();
        $draft = $this->contract(['status' => 'draft']);
        $this->getJson('/api/contracts/'.$draft->id.'/payment-context')->assertUnprocessable();
        $this->postJson('/api/contracts/'.$draft->id.'/transition', ['action' => 'cancel', 'reason' => 'إلغاء', 'request_key' => (string) Str::uuid()])->assertOk();
        $this->getJson('/api/contracts/'.$draft->id.'/payment-context')->assertUnprocessable();
        $c = $this->contract();
        $p = $this->pay($c);
        $this->postJson('/api/payments/'.$p->id.'/reverse', ['reason' => 'خطأ', 'request_key' => (string) Str::uuid()])->assertOk();
        $this->getJson('/api/contracts/'.$c->id.'/payment-context?corrects_payment_id='.$p->id)->assertUnprocessable();
    }

    public function test_paid_history_remains_protected_and_payments_cannot_be_deleted_or_directly_edited(): void
    {
        $this->owner();
        $c = $this->contract();
        $p = $this->pay($c);
        $this->putJson('/api/payments/'.$p->id, ['amount' => '1'])->assertStatus(405);
        $this->deleteJson('/api/payments/'.$p->id)->assertStatus(405);
        $this->postJson('/api/contracts/'.$c->id.'/schedule', ['mode' => 'automatic', 'reason' => 'تغيير', 'expected_version' => 1, 'request_key' => (string) Str::uuid()])->assertUnprocessable();
        $this->postJson('/api/contracts/'.$c->id.'/transition', ['action' => 'cancel', 'reason' => 'إلغاء', 'request_key' => (string) Str::uuid()])->assertUnprocessable();
        $this->assertDatabaseCount('payments', 1);
    }

    public function test_payment_history_filters_and_all_page_totals_exclude_reversals(): void
    {
        $this->owner();
        $c = $this->contract();
        $due = $c->dues()->firstOrFail();
        $last = null;
        for ($i = 0; $i < 21; $i++) {
            $last = app(PaymentService::class)->record($this->input($c, [$due->id => '1.00']), $this->user->id);
        }
        app(PaymentService::class)->reverse($last, ['reason' => 'تصحيح', 'request_key' => (string) Str::uuid()], $this->user->id);
        $this->getJson('/api/payments')->assertJsonCount(20, 'data')->assertJsonPath('meta.total', 21)->assertJsonPath('summary.collected_total', '20.00')->assertJsonPath('summary.reversed_total', '1.00');
        $this->getJson('/api/payments?page=2')->assertJsonCount(1, 'data')->assertJsonPath('summary.collected_total', '20.00');
        $this->getJson('/api/payments?status=reversed')->assertJsonCount(1, 'data')->assertJsonPath('summary.collected_total', '0.00');
        $this->getJson('/api/payments?search='.$last->receipt_number)->assertJsonCount(1, 'data');
        $this->getJson('/api/payments?search='.urlencode('٠١٠١٢٣٤٥٦٧٨'))->assertJsonPath('meta.total', 21);
        $this->getJson('/api/payments?customer_id='.$this->customer->id.'&building_id='.$this->unit->building_id.'&method=cash&paid_to=2026-11-08')->assertJsonPath('meta.total', 21);
        $this->getJson('/api/payments?paid_from=2026-12-01&paid_to=2026-01-01')->assertUnprocessable();
    }

    public function test_legacy_records_do_not_get_fabricated_receipts(): void
    {
        $this->owner();
        $c = $this->contract();
        $legacy = Payment::create(['contract_id' => $c->id, 'user_id' => $this->user->id, 'amount' => '10.00', 'paid_on' => '2026-11-08', 'request_key' => (string) Str::uuid(), 'request_hash' => str_repeat('a', 64)]);
        $this->getJson('/api/payments/'.$legacy->id)->assertOk()->assertJsonPath('data.receipt', null);
        $this->getJson('/api/payments/'.$legacy->id.'/receipt')->assertUnprocessable()->assertJsonValidationErrors('receipt');
    }

    public function test_exact_cent_after_large_payment_and_correction(): void
    {
        $this->owner();
        $c = $this->contract(['amount' => '999999999999.99']);
        $due = $c->dues()->firstOrFail();
        $p = $this->pay($c,[$due->id => '999999999999.98']);
        $this->getJson('/api/dues?contract_id='.$c->id)->assertJsonPath('summary.principal.remaining','0.01');
        $input = $this->input($c,[$due->id => '999999999999.97'],$p->id);
        $input['reason'] = 'تصحيح قرش';
        $this->postJson('/api/payments/'.$p->id.'/correct',$input)->assertCreated();
        $this->getJson('/api/dues?contract_id='.$c->id)->assertJsonPath('summary.principal.remaining','0.02');
    }
}
