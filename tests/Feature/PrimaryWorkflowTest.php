<?php

namespace Tests\Feature;

use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class PrimaryWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-11-08 12:00:00', 'Africa/Cairo'));
        User::factory()->create(['name' => 'المالك', 'email' => 'owner@example.com', 'password' => 'WorkflowTest123!']);
    }

    private function request(string $method, string $path, array $data = []): TestResponse
    {
        // Each real HTTP request resolves authentication afresh. Laravel's test app is reused.
        $this->app['auth']->forgetGuards();

        return $this->json($method, '/api'.$path, $data);
    }

    private function loginAndCreateProperty(): array
    {
        $login = $this->request('POST', '/auth/login', ['email' => 'owner@example.com', 'password' => 'WorkflowTest123!'])->assertOk();
        $this->withToken($login->json('data.token'));
        $building = $this->request('POST', '/buildings', ['name' => 'مبنى النيل', 'address' => 'المعادي، القاهرة'])->assertCreated()->json('data.id');
        $unit = $this->request('POST', '/units', ['building_id' => $building, 'number' => '١٠١', 'floor' => 1, 'type' => 'apartment', 'area' => '125.50', 'asking_sale_price' => '1200000.00'])->assertCreated()->assertJsonPath('data.number', '101')->json('data.id');
        $customer = $this->request('POST', '/customers', ['name' => 'أحمد محمد', 'phone' => '٠١٠١٢٣٤٥٦٧٨'])->assertCreated()->assertJsonPath('data.phone', '+201012345678')->json('data.id');

        return ['building_id' => $building, 'unit_id' => $unit, 'customer_id' => $customer];
    }

    private function transition(int $contract, string $action, array $extra = []): TestResponse
    {
        return $this->request('POST', '/contracts/'.$contract.'/transition', array_merge(['action' => $action, 'reason' => 'تأكيد المالك في تجربة سير العمل', 'request_key' => (string) Str::uuid()], $extra));
    }

    private function paymentInput(int $contract, array $amounts, ?int $corrects = null): array
    {
        $context = $this->request('GET', '/contracts/'.$contract.'/payment-context'.($corrects ? '?corrects_payment_id='.$corrects : ''))->assertOk()->json('data');
        $lines = [];
        $total = 0;
        foreach ($amounts as $id => $amount) {
            $row = collect($context['dues'])->firstWhere('id', $id);
            $this->assertNotNull($row, 'The selected due must be present in the HTTP payment context.');
            [$whole, $fraction] = array_pad(explode('.', $amount, 2), 2, '');
            $total += (int) $whole * 100 + (int) str_pad($fraction, 2, '0');
            $lines[] = ['due_id' => $id, 'amount' => $amount, 'expected_remaining' => $row['remaining']];
        }

        return ['contract_id' => $contract, 'amount' => sprintf('%d.%02d', intdiv($total, 100), $total % 100), 'paid_on' => '2026-11-08', 'method' => 'transfer', 'reference' => 'اختبار سير العمل', 'expected_schedule_version' => $context['schedule_version'], 'expected_principal_remaining' => $context['remaining']['principal'], 'expected_deposit_remaining' => $context['remaining']['deposit'], 'allocations' => $lines, 'request_key' => (string) Str::uuid()];
    }

    public function test_installment_sale_from_real_login_through_collection_correction_receipts_reports_and_logout(): void
    {
        $property = $this->loginAndCreateProperty();
        $terms = ['unit_id' => $property['unit_id'], 'customer_id' => $property['customer_id'], 'type' => 'sale', 'sale_mode' => 'installments', 'status' => 'draft', 'start_date' => '2026-11-08', 'amount' => '1000000.01', 'down_payment' => '100000.00', 'installments_count' => 3, 'frequency' => 'monthly', 'first_due_date' => '2026-12-01', 'request_key' => (string) Str::uuid()];
        $contract = $this->request('POST', '/contracts', $terms)->assertCreated()->json('data.id');
        $this->request('GET', '/dues?contract_id='.$contract)->assertJsonCount(0, 'data');
        $this->request('GET', '/units/'.$property['unit_id'])->assertJsonPath('data.status', 'available');
        $this->transition($contract, 'activate')->assertOk();
        $this->request('GET', '/units/'.$property['unit_id'])->assertJsonPath('data.status', 'sold');
        $dues = $this->request('GET', '/dues?contract_id='.$contract)->assertJsonPath('summary.principal.scheduled', '1000000.01')->json('data');
        $down = collect($dues)->firstWhere('kind', 'down_payment');
        $installment = collect($dues)->firstWhere('kind', 'sale_installment');
        $this->assertSame('300000.01', $installment['amount']);
        $input = $this->paymentInput($contract, [$down['id'] => '100000.00', $installment['id'] => '50000.01']);
        $this->request('POST', '/payments/preview', $input)->assertJsonPath('data.contract_remaining_after.principal', '850000.00');
        $receipt = $this->request('POST', '/payments', $input)->assertCreated()->json('data');
        $this->request('POST', '/payments', $input)->assertOk()->assertJsonPath('data.id', $receipt['id']);
        $this->request('GET', '/payments/'.$receipt['id'].'/receipt')->assertOk()->assertJsonPath('data.receipt.amount', '150000.01');
        $correction = $this->paymentInput($contract, [$down['id'] => '100000.00', $installment['id'] => '40000.00'], $receipt['id']);
        $correction['reason'] = 'تصحيح قيمة التحويل';
        $replacement = $this->request('POST', '/payments/'.$receipt['id'].'/correct', $correction)->assertCreated()->assertJsonPath('data.corrects_payment_id', $receipt['id'])->json('data.id');
        $this->request('GET', '/payments/'.$receipt['id'])->assertJsonPath('data.status', 'reversed')->assertJsonPath('data.receipt.amount', '150000.01')->assertJsonPath('data.replacement_payment_id', $replacement);
        $this->request('GET', '/dashboard')->assertJsonPath('data.collections.principal_collected', '140000.00');
        $this->request('GET', '/reports?type=contracts&date_from=2026-11-01&date_to=2027-12-31')->assertJsonPath('report.summary.contracts.signed_sales_value', '1000000.01')->assertJsonPath('report.summary.collections.collected_total', '140000.00')->assertJsonPath('report.summary.dues.principal.remaining', '860000.01');
        $this->request('GET', '/reports/print?type=collections&date_from=2026-11-01&date_to=2027-12-31')->assertJsonPath('total', 2);
        $this->request('POST', '/payments/'.$replacement.'/reverse', ['reason' => 'إلغاء التحويل التجريبي', 'request_key' => (string) Str::uuid()])->assertOk();
        $this->request('GET', '/dues?contract_id='.$contract)->assertJsonPath('summary.principal.remaining', '1000000.01')->assertJsonPath('summary.principal.paid', '0.00');
        $this->request('POST', '/auth/logout')->assertOk();
        $this->request('GET', '/dashboard')->assertUnauthorized();
    }

    public function test_rental_from_property_creation_through_overlap_rejection_renewal_and_separate_deposit_collection(): void
    {
        $property = $this->loginAndCreateProperty();
        $terms = ['unit_id' => $property['unit_id'], 'customer_id' => $property['customer_id'], 'type' => 'rental', 'status' => 'active', 'start_date' => '2026-11-01', 'end_date' => '2026-11-30', 'amount' => '10000.00', 'security_deposit' => '5000.00', 'frequency' => 'monthly', 'request_key' => (string) Str::uuid()];
        $contract = $this->request('POST', '/contracts', $terms)->assertCreated()->json('data.id');
        $this->request('POST', '/contracts', array_merge($terms, ['start_date' => '2026-11-30', 'end_date' => '2026-12-30', 'request_key' => (string) Str::uuid()]))->assertUnprocessable();
        $renewal = $this->request('POST', '/contracts', array_merge($terms, ['renewed_from_id' => $contract, 'start_date' => '2026-12-01', 'end_date' => '2026-12-31', 'amount' => '11000.00', 'security_deposit' => '0', 'request_key' => (string) Str::uuid()]))->assertCreated()->json('data.id');
        $this->request('GET', '/contracts/'.$renewal)->assertJsonPath('data.renewed_from_id', $contract);
        $dues = $this->request('GET', '/dues?contract_id='.$contract)->assertOk()->json('data');
        $rent = collect($dues)->firstWhere('kind', 'rent');
        $deposit = collect($dues)->firstWhere('kind', 'security_deposit');
        $input = $this->paymentInput($contract, [$rent['id'] => '6000.00', $deposit['id'] => '2000.00']);
        $receipt = $this->request('POST', '/payments', $input)->assertCreated()->assertJsonPath('data.receipt.principal_amount', '6000.00')->assertJsonPath('data.receipt.deposit_amount', '2000.00')->json('data.id');
        $this->request('GET', '/dues?customer_id='.$property['customer_id'])->assertJsonPath('summary.principal.remaining', '15000.00')->assertJsonPath('summary.deposit.remaining', '3000.00');
        $this->request('GET', '/reports?date_from=2026-11-01&date_to=2026-11-08')->assertJsonPath('report.summary.collections.principal_collected', '6000.00')->assertJsonPath('report.summary.collections.deposit_collected', '2000.00')->assertJsonPath('report.summary.contracts.rental_scheduled_value', '10000.00');
        $this->request('POST', '/payments/'.$receipt.'/reverse', ['reason' => 'تصحيح التحصيل التجريبي', 'request_key' => (string) Str::uuid()])->assertOk();
        $this->request('GET', '/dues?customer_id='.$property['customer_id'])->assertJsonPath('summary.principal.remaining', '21000.00')->assertJsonPath('summary.deposit.remaining', '5000.00');
        // Successor must be cancelled before shortening the original term.
        $this->transition($contract, 'terminate', ['effective_date' => '2026-11-07', 'settlement_mode' => 'keep'])->assertUnprocessable();
        $this->transition($renewal, 'cancel')->assertOk();
        $this->transition($contract, 'terminate', ['effective_date' => '2026-11-07', 'settlement_mode' => 'keep'])
            ->assertOk()
            ->assertJsonPath('data.status', 'ended')
            ->assertJsonPath('data.effective_end_date', '2026-11-07');
        $this->request('GET', '/contracts/'.$contract)->assertJsonPath('data.end_date', '2026-11-30');
        // Allocation history remains, so keep leaves the November rent collectible.
        $this->request('GET', '/dues?contract_id='.$contract)->assertJsonPath('summary.principal.remaining', '10000.00');
    }

    public function test_unpaid_rental_termination_review_renewal_late_collection_and_cancelled_successor(): void
    {
        $property = $this->loginAndCreateProperty();
        $terms = ['unit_id' => $property['unit_id'], 'customer_id' => $property['customer_id'], 'type' => 'rental', 'status' => 'active', 'start_date' => '2026-11-01', 'end_date' => '2026-11-30', 'amount' => '10000.00', 'security_deposit' => '2000.00', 'frequency' => 'monthly', 'request_key' => (string) Str::uuid()];
        $contract = $this->request('POST', '/contracts', $terms)->assertCreated()->json('data.id');
        $this->request('POST', '/contracts/'.$contract.'/schedule/preview', ['mode' => 'automatic', 'termination_date' => '2026-11-07'])->assertOk()->assertJsonPath('data.principal_total', '2333.33');
        $this->request('GET', '/dues?contract_id='.$contract)->assertJsonPath('summary.principal.scheduled', '10000.00');
        $this->transition($contract, 'terminate', ['effective_date' => '2026-11-07'])->assertOk()->assertJsonPath('data.end_date', '2026-11-30')->assertJsonPath('data.effective_end_date', '2026-11-07');
        $renewal = $this->request('POST', '/contracts', array_merge($terms, ['renewed_from_id' => $contract, 'start_date' => '2026-11-08', 'security_deposit' => '0', 'request_key' => (string) Str::uuid()]))->assertCreated()->json('data.id');
        $this->request('GET', '/units/'.$property['unit_id'])->assertJsonPath('data.status', 'rented');
        $this->request('GET', '/dues?contract_id='.$renewal)->assertJsonPath('summary.principal.scheduled', '7666.67');
        $dues = $this->request('GET', '/dues?contract_id='.$contract)->assertOk()->json('data');
        $rent = collect($dues)->firstWhere('kind', 'rent');
        $input = $this->paymentInput($contract, [$rent['id'] => '2333.33']);
        $this->request('POST', '/payments', $input)->assertCreated();
        $this->transition($renewal, 'cancel')->assertOk();
        $this->request('GET', '/units/'.$property['unit_id'])->assertJsonPath('data.status', 'available');
        $this->request('GET', '/dues?customer_id='.$property['customer_id'])->assertJsonPath('summary.principal.remaining', '0.00')->assertJsonPath('summary.deposit.remaining', '2000.00');
        $this->request('GET', '/reports?date_from=2026-11-01&date_to=2026-11-08')->assertJsonPath('report.summary.contracts.rental_scheduled_value', '2333.33')->assertJsonPath('report.summary.collections.principal_collected', '2333.33');
        $this->request('GET', '/dues?contract_id='.$contract.'&status=voided')->assertJsonCount(2, 'data');
    }

    public function test_cash_sale_fully_collected_receipted_and_completed_cannot_be_sold_again(): void
    {
        $property = $this->loginAndCreateProperty();
        $terms = ['unit_id' => $property['unit_id'], 'customer_id' => $property['customer_id'], 'type' => 'sale', 'sale_mode' => 'cash', 'status' => 'active', 'start_date' => '2026-11-08', 'amount' => '100.01', 'request_key' => (string) Str::uuid()];
        $contract = $this->request('POST', '/contracts', $terms)->assertCreated()->json('data.id');
        $due = $this->request('GET', '/dues?contract_id='.$contract)->assertOk()->json('data.0');
        $receipt = $this->request('POST', '/payments', $this->paymentInput($contract, [$due['id'] => '100.01']))->assertCreated()->json('data.id');
        $this->transition($contract, 'complete')->assertOk()->assertJsonPath('data.status', 'completed');
        $this->request('GET', '/payments/'.$receipt.'/receipt')->assertOk()->assertJsonPath('data.receipt.amount', '100.01');
        $this->request('GET', '/dashboard')->assertJsonPath('data.collections.principal_collected', '100.01')->assertJsonPath('data.balances.principal.remaining', '0.00')->assertJsonPath('data.counts.sold_units', 1);
        $this->request('POST', '/contracts', array_merge($terms, ['request_key' => (string) Str::uuid()]))->assertUnprocessable()->assertJsonValidationErrors('unit_id');
    }
}
