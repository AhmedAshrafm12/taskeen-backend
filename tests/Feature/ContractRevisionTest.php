<?php

namespace Tests\Feature;

use App\Models\Building;
use App\Models\CashTransaction;
use App\Models\Contract;
use App\Models\Customer;
use App\Models\Payment;
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

class ContractRevisionTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-11-08 12:00:00', 'Africa/Cairo'));
        $this->user = User::factory()->create();
        $this->customer = Customer::create(['name' => 'أحمد محمد', 'phone' => '01012345678']);
        Sanctum::actingAs($this->user);
    }

    private function unit(): Unit
    {
        $building = Building::create(['name' => 'برج النيل', 'address' => 'القاهرة']);

        return Unit::create(['building_id' => $building->id, 'number' => (string) Str::uuid()]);
    }

    private function installment(): Contract
    {
        return app(ContractService::class)->create([
            'unit_id' => $this->unit()->id,
            'customer_id' => $this->customer->id,
            'type' => 'sale',
            'sale_mode' => 'installments',
            'status' => 'active',
            'start_date' => '2027-01-01',
            'amount' => '1200000.00',
            'down_payment' => '300000.00',
            'installments_count' => 6,
            'frequency' => 'monthly',
            'first_due_date' => '2027-01-01',
            'request_key' => (string) Str::uuid(),
        ], $this->user->id);
    }

    private function pay(Contract $c, array $byKind, string $date = '2027-01-05'): Payment
    {
        $service = app(PaymentService::class);
        $ctx = $service->context($c->fresh());
        $dues = collect($ctx['dues']);
        $lines = [];
        $total = 0;
        foreach ($byKind as $kind => $amount) {
            $due = $dues->firstWhere('kind', $kind) ?? $dues->where('kind', $kind)->values();
            if (is_array($amount)) {
                $rows = $dues->where('kind', $kind)->values();
                foreach ($amount as $i => $value) {
                    $lines[] = ['due_id' => $rows[$i]['id'], 'amount' => $value, 'expected_remaining' => $rows[$i]['remaining']];
                    $total += Money::cents($value);
                }
            } else {
                $row = $dues->firstWhere('kind', $kind);
                $lines[] = ['due_id' => $row['id'], 'amount' => $amount, 'expected_remaining' => $row['remaining']];
                $total += Money::cents($amount);
            }
        }

        return $service->record([
            'contract_id' => $c->id,
            'amount' => Money::decimal($total),
            'paid_on' => $date,
            'method' => 'cash',
            'expected_schedule_version' => $ctx['schedule_version'],
            'expected_principal_remaining' => $ctx['remaining']['principal'],
            'expected_deposit_remaining' => $ctx['remaining']['deposit'],
            'allocations' => $lines,
            'reason' => 'تحصيل اختبار التعديل',
            'request_key' => (string) Str::uuid(),
        ], $this->user->id);
    }

    private function paid(Contract $c): Contract
    {
        $this->pay($c, ['down_payment' => '300000.00']);
        $this->pay($c->fresh(), ['sale_installment' => ['150000.00', '150000.00']]);

        return $c->fresh();
    }

    private function manualWithSurplus($dues): array
    {
        $last = $dues->count() - 1;

        return $dues->values()->map(function ($due, $i) use ($last) {
            $amount = $due->amount;
            if ($due->kind === 'sale_installment' && $i === 1) {
                $amount = '130000.00';
            }
            if ($due->kind === 'sale_installment' && $i === $last) {
                $amount = '170000.00';
            }

            return ['id' => $due->id, 'amount' => $amount, 'due_date' => $due->due_date->toDateString()];
        })->all();
    }

    private function revisionPayload(Contract $c, array $extra = []): array
    {
        return array_merge([
            'amount' => $c->amount,
            'start_date' => $c->start_date->toDateString(),
            'down_payment' => $c->down_payment,
            'installments_count' => $c->installments_count,
            'frequency' => $c->frequency,
            'first_due_date' => $c->first_due_date?->toDateString(),
            'date_scope' => 'all_dues',
            'amount_scope' => 'redistribute_unpaid',
            'expected_schedule_version' => (int) $c->schedules()->max('version'),
            'expected_principal_paid' => '600000.00',
            'reason' => 'تصحيح شروط العقد بعد مراجعة المالك',
            'request_key' => (string) Str::uuid(),
        ], $extra);
    }

    public function test_shifting_all_due_dates_keeps_paid_on_and_cash(): void
    {
        $c = $this->paid($this->installment());
        $payment = Payment::where('contract_id', $c->id)->orderBy('id')->first();
        $cash = CashTransaction::sum('signed_amount');
        $dueId = $c->dues()->where('kind', 'sale_installment')->orderBy('due_date')->value('id');
        $this->postJson('/api/contracts/'.$c->id.'/revisions', $this->revisionPayload($c, [
            'start_date' => '2026-12-01',
            'first_due_date' => '2026-12-01',
            'date_scope' => 'all_dues',
        ]))->assertOk();
        $this->assertSame('2026-12-01', $c->fresh()->dues()->find($dueId)->due_date->toDateString());
        $this->assertSame('2027-01-05', $payment->fresh()->paid_on->toDateString());
        $this->assertEquals($cash, CashTransaction::sum('signed_amount'));
    }

    public function test_reducing_amount_redistributes_unpaid_only(): void
    {
        $c = $this->paid($this->installment());
        $this->postJson('/api/contracts/'.$c->id.'/revisions', $this->revisionPayload($c, [
            'amount' => '1100000.00',
            'date_scope' => 'contract_only',
        ]))->assertOk()->assertJsonPath('data.contract.amount', '1100000.00');
        $paidInstallments = $c->fresh()->dues()->where('kind', 'sale_installment')->where('is_void', false)->orderBy('due_date')->get();
        $this->assertEquals('150000.00', $paidInstallments[0]->amount);
        $this->assertEquals('150000.00', $paidInstallments[1]->amount);
        $this->assertEquals('125000.00', $paidInstallments[2]->amount);
        $this->assertEquals('125000.00', $paidInstallments[5]->amount);
        $this->assertEquals('300000.00', $c->fresh()->dues()->where('kind', 'down_payment')->value('amount'));
    }

    public function test_reducing_paid_due_reallocates_surplus(): void
    {
        $c = $this->paid($this->installment());
        $dues = $c->dues()->where('is_void', false)->where('kind', '!=', 'security_deposit')->orderBy('due_date')->orderBy('id')->get();
        $manual = $this->manualWithSurplus($dues);
        $firstInstallment = $dues->firstWhere('kind', 'sale_installment');
        $this->postJson('/api/contracts/'.$c->id.'/revisions', $this->revisionPayload($c, [
            'date_scope' => 'contract_only',
            'amount_scope' => 'manual_dues',
            'dues' => $manual,
            'surplus_action' => 'reallocate',
        ]))->assertOk();
        $this->assertEquals('130000.00', $firstInstallment->fresh()->amount);
        $this->assertSame(13000000, $firstInstallment->fresh()->paidCents());
    }

    public function test_reducing_paid_due_can_credit_customer(): void
    {
        $c = $this->paid($this->installment());
        $dues = $c->dues()->where('is_void', false)->where('kind', '!=', 'security_deposit')->orderBy('due_date')->orderBy('id')->get();
        $manual = $this->manualWithSurplus($dues);
        $cash = CashTransaction::sum('signed_amount');
        $this->postJson('/api/contracts/'.$c->id.'/revisions', $this->revisionPayload($c, [
            'date_scope' => 'contract_only',
            'amount_scope' => 'manual_dues',
            'dues' => $manual,
            'surplus_action' => 'credit',
        ]))->assertOk()->assertJsonPath('data.contract.credit_balance', '20000.00');
        $this->assertEquals($cash, CashTransaction::sum('signed_amount'));
    }

    public function test_refund_posts_cash_out_and_blocks_when_cash_is_short(): void
    {
        $c = $this->paid($this->installment());
        $dues = $c->dues()->where('is_void', false)->where('kind', '!=', 'security_deposit')->orderBy('due_date')->orderBy('id')->get();
        $manual = $this->manualWithSurplus($dues);
        $base = $this->revisionPayload($c, [
            'date_scope' => 'contract_only',
            'amount_scope' => 'manual_dues',
            'dues' => $manual,
            'surplus_action' => 'refund',
            'refund_confirmed' => true,
        ]);
        CashTransaction::query()->update(['signed_amount' => 0]);
        $this->postJson('/api/contracts/'.$c->id.'/revisions', $base)->assertUnprocessable();
        CashTransaction::query()->update(['signed_amount' => 600000]);
        $this->postJson('/api/contracts/'.$c->id.'/revisions', array_merge($base, ['request_key' => (string) Str::uuid()]))
            ->assertOk();
        $this->assertTrue(CashTransaction::where('source_type', 'contract_refund')->exists());
    }

    public function test_rental_unpaid_only_date_and_amount_revision(): void
    {
        $c = app(ContractService::class)->create([
            'unit_id' => $this->unit()->id,
            'customer_id' => $this->customer->id,
            'type' => 'rental',
            'status' => 'active',
            'start_date' => '2026-11-01',
            'end_date' => '2026-12-31',
            'amount' => '10000.00',
            'frequency' => 'monthly',
            'security_deposit' => '1000.00',
            'request_key' => (string) Str::uuid(),
        ], $this->user->id);
        $rent = $c->dues()->where('kind', 'rent')->orderBy('due_date')->get();
        $this->pay($c, ['rent' => [$rent[0]->amount]]);
        $paidId = $rent[0]->id;
        $openId = $rent[1]->id;
        $paidDate = $rent[0]->due_date->toDateString();
        $this->postJson('/api/contracts/'.$c->id.'/revisions', [
            'amount' => '9000.00',
            'start_date' => '2026-10-01',
            'end_date' => '2026-11-30',
            'frequency' => 'monthly',
            'security_deposit' => '1000.00',
            'date_scope' => 'unpaid_only',
            'amount_scope' => 'redistribute_unpaid',
            'expected_schedule_version' => 1,
            'expected_principal_paid' => $rent[0]->amount,
            'reason' => 'تصحيح مدة الإيجار',
            'request_key' => (string) Str::uuid(),
        ])->assertOk();
        $this->assertSame($paidDate, $c->fresh()->dues()->find($paidId)->due_date->toDateString());
        $this->assertNotSame($paidDate, $c->fresh()->dues()->find($openId)?->due_date?->toDateString() ?? '');
    }

    public function test_concurrent_edit_is_rejected(): void
    {
        $c = $this->paid($this->installment());
        $this->postJson('/api/contracts/'.$c->id.'/revisions', $this->revisionPayload($c, [
            'expected_principal_paid' => '10.00',
        ]))->assertUnprocessable();
        $this->postJson('/api/contracts/'.$c->id.'/revisions', $this->revisionPayload($c, [
            'expected_schedule_version' => 99,
        ]))->assertUnprocessable();
    }

    public function test_request_key_is_idempotent(): void
    {
        $c = $this->paid($this->installment());
        $payload = $this->revisionPayload($c, [
            'amount' => '1100000.00',
            'date_scope' => 'contract_only',
        ]);
        $this->postJson('/api/contracts/'.$c->id.'/revisions', $payload)->assertOk();
        $this->postJson('/api/contracts/'.$c->id.'/revisions', $payload)->assertOk();
        $this->assertDatabaseCount('contract_revisions', 1);
    }

    public function test_draft_and_cancelled_are_rejected(): void
    {
        $draft = app(ContractService::class)->create([
            'unit_id' => $this->unit()->id,
            'customer_id' => $this->customer->id,
            'type' => 'sale',
            'sale_mode' => 'cash',
            'status' => 'draft',
            'start_date' => '2026-11-08',
            'amount' => '100.00',
            'request_key' => (string) Str::uuid(),
        ], $this->user->id);
        $payload = [
            'amount' => '90.00',
            'start_date' => '2026-11-08',
            'date_scope' => 'contract_only',
            'amount_scope' => 'redistribute_unpaid',
            'expected_schedule_version' => 1,
            'expected_principal_paid' => '0.00',
            'reason' => 'محاولة تعديل غير مسموح',
            'request_key' => (string) Str::uuid(),
        ];
        $this->postJson('/api/contracts/'.$draft->id.'/revisions', $payload)->assertUnprocessable();
        $active = app(ContractService::class)->create([
            'unit_id' => $this->unit()->id,
            'customer_id' => $this->customer->id,
            'type' => 'sale',
            'sale_mode' => 'cash',
            'status' => 'active',
            'start_date' => '2026-11-08',
            'amount' => '100.00',
            'request_key' => (string) Str::uuid(),
        ], $this->user->id);
        $this->postJson('/api/contracts/'.$active->id.'/transition', [
            'action' => 'cancel', 'reason' => 'إلغاء لاختبار التعديل', 'request_key' => (string) Str::uuid(),
        ])->assertOk();
        $this->postJson('/api/contracts/'.$active->id.'/revisions', array_merge($payload, ['request_key' => (string) Str::uuid()]))->assertUnprocessable();
    }
}
