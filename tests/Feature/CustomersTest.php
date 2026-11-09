<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CustomersTest extends TestCase
{
    use RefreshDatabase;

    private function owner(): void
    {
        Sanctum::actingAs(User::factory()->create());
    }

    private function input(array $overrides = []): array
    {
        return array_merge(['name' => 'أحمد محمد', 'phone' => '01012345678'], $overrides);
    }

    public function test_customer_endpoints_require_login(): void
    {
        $this->getJson('/api/customers')->assertUnauthorized();
        $this->getJson('/api/customers/1')->assertUnauthorized();
        $this->postJson('/api/customers')->assertUnauthorized();
        $this->putJson('/api/customers/1')->assertUnauthorized();
    }

    public function test_create_view_edit_and_clear_optional_details(): void
    {
        $this->owner();
        $response = $this->postJson('/api/customers', $this->input([
            'email' => 'ahmed@example.com', 'address' => 'مدينة نصر، القاهرة', 'notes' => 'يفضل التواصل مساءً',
        ]))->assertCreated()->assertJsonPath('data.phone', '+201012345678')->assertJsonPath('data.name', 'أحمد محمد');
        $id = $response->json('data.id');
        $this->getJson('/api/customers/'.$id)->assertOk()->assertJsonPath('data.notes', 'يفضل التواصل مساءً');
        $this->putJson('/api/customers/'.$id, $this->input(['name' => 'أحمد محمود', 'email' => '', 'notes' => null, 'address' => null]))
            ->assertOk()->assertJsonPath('data.name', 'أحمد محمود')->assertJsonPath('data.email', null)->assertJsonPath('data.notes', null)->assertJsonPath('data.address', null);
        $this->assertDatabaseCount('customers', 1);
        $this->getJson('/api/customers/999999')->assertNotFound();
        $this->deleteJson('/api/customers/'.$id)->assertStatus(405);
        $this->assertDatabaseCount('customers', 1);
    }

    public function test_validation_and_normalization_of_phone_formats(): void
    {
        $this->owner();
        $this->postJson('/api/customers', [])->assertUnprocessable()->assertJsonValidationErrors(['name', 'phone']);
        foreach ([['phone' => 'abc01012345678'], ['phone' => '123'], ['email' => 'invalid'], ['name' => '  '], ['allow_duplicate_phone' => 'yes']] as $invalid) {
            $this->postJson('/api/customers', $this->input($invalid))->assertUnprocessable();
        }
        foreach (['010 1234-5678', '٠١٠١٢٣٤٥٦٧٨', '۰۱۰۱۲۳۴۵۶۷۸', '00201012345678', '+20 (0)10 1234 5678', '201012345678', '1012345678'] as $phone) {
            $this->assertSame('+201012345678', Customer::normalizePhone($phone));
        }
        $this->postJson('/api/customers', $this->input(['phone' => '+44 7700 900123']))->assertCreated()->assertJsonPath('data.phone', '+447700900123');
    }

    public function test_duplicates_warn_before_writes_and_allow_explicit_shared_phone(): void
    {
        $this->owner();
        $original = $this->postJson('/api/customers', $this->input())->assertCreated()->json('data.id');
        foreach (['٠١٠١٢٣٤٥٦٧٨', '+201012345678', '00201012345678'] as $phone) {
            $this->postJson('/api/customers', $this->input(['name' => 'سارة محمد', 'phone' => $phone]))
                ->assertStatus(409)->assertJsonPath('duplicates.0.id', $original)->assertJsonPath('duplicates.0.name', 'أحمد محمد');
        }
        $this->assertDatabaseCount('customers', 1);
        $second = $this->postJson('/api/customers', $this->input(['name' => 'سارة محمد', 'allow_duplicate_phone' => true]))->assertCreated()->json('data.id');
        $this->putJson('/api/customers/'.$second, $this->input(['name' => 'سارة محمود']))->assertOk();
        $this->assertDatabaseCount('customers', 2);
        $this->assertDatabaseHas('customers', ['id' => $original, 'name' => 'أحمد محمد']);
    }

    public function test_update_warns_without_overwriting_before_confirmation(): void
    {
        $this->owner();
        $this->postJson('/api/customers', $this->input())->assertCreated();
        $id = $this->postJson('/api/customers', $this->input(['name' => 'محمود حسن', 'phone' => '01112345678']))->assertCreated()->json('data.id');
        $this->putJson('/api/customers/'.$id, $this->input(['name' => 'اسم جديد']))->assertStatus(409);
        $this->assertDatabaseHas('customers', ['id' => $id, 'name' => 'محمود حسن', 'phone' => '+201112345678']);
        $this->putJson('/api/customers/'.$id, $this->input(['name' => 'اسم جديد', 'allow_duplicate_phone' => true]))->assertOk();
        $this->assertDatabaseHas('customers', ['id' => $id, 'name' => 'اسم جديد', 'phone' => '+201012345678']);
    }

    public function test_search_by_arabic_name_local_phone_and_pagination(): void
    {
        $this->owner();
        $this->postJson('/api/customers', $this->input())->assertCreated();
        for ($i = 0; $i < 21; $i++) {
            Customer::create($this->input(['name' => 'عميل '.$i, 'phone' => '+201100000'.str_pad((string) $i, 3, '0', STR_PAD_LEFT)]));
        }
        $this->getJson('/api/customers')->assertOk()->assertJsonCount(20, 'data')->assertJsonPath('meta.total', 22);
        $this->getJson('/api/customers?page=2')->assertJsonCount(2, 'data');
        foreach (['أحمد', '01012345678', '٠١٠١٢٣٤٥٦٧٨', '+20 10 1234 5678', '010123'] as $search) {
            $this->getJson('/api/customers?search='.urlencode($search))->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', 'أحمد محمد');
        }
        $this->getJson('/api/customers?search='.urlencode('غير موجود'))->assertJsonCount(0, 'data');
        $this->getJson('/api/customers?page=-1')->assertUnprocessable();
        $this->getJson('/api/customers?search=---')->assertJsonCount(0, 'data');
    }
}
