<?php

namespace Tests\Feature;

use App\Models\Building;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class FoundationBuildingsTest extends TestCase
{
    use RefreshDatabase;

    private function owner(): User
    {
        return User::factory()->create(['email' => 'owner@example.com', 'password' => Hash::make('correct-password')]);
    }

    public function test_buildings_require_authentication(): void
    {
        foreach (['get', 'post', 'put', 'delete'] as $method) {
            $this->{$method.'Json'}('/api/buildings'.(in_array($method, ['put', 'delete']) ? '/1' : ''))->assertUnauthorized()->assertJsonStructure(['message']);
        }
        $this->getJson('/api/buildings/1/units')->assertUnauthorized();
    }

    public function test_login_logout_and_revocation(): void
    {
        $this->owner();
        $this->postJson('/api/auth/login', ['login' => 'owner@example.com', 'password' => 'wrong'])->assertUnprocessable()->assertJsonValidationErrors('login');
        $response = $this->postJson('/api/auth/login', ['login' => 'owner@example.com', 'password' => 'correct-password'])
            ->assertOk()
            ->assertJsonStructure(['data' => ['token', 'expires_at', 'user']]);
        $token = $response->json('data.token');
        $this->assertNotNull($response->json('data.expires_at'));
        $this->withToken($token)->getJson('/api/auth/me')->assertOk()->assertJsonPath('data.email', 'owner@example.com')->assertJsonMissingPath('data.password')->assertJsonStructure(['data' => ['id', 'name', 'username', 'email']]);
        $this->withToken($token)->postJson('/api/auth/logout')->assertOk();
        $this->assertDatabaseCount('personal_access_tokens', 0);
        $this->app['auth']->forgetGuards();
        $this->withToken($token)->getJson('/api/buildings')->assertUnauthorized();
    }

    public function test_expired_tokens_are_rejected(): void
    {
        $user = $this->owner();
        $token = $user->createToken('expired', ['*'], now()->subMinute())->plainTextToken;
        $this->withToken($token)->getJson('/api/buildings')->assertUnauthorized();
    }

    public function test_building_validation_crud_and_preserved_units(): void
    {
        Sanctum::actingAs($this->owner());
        $this->postJson('/api/buildings', ['name' => '  ', 'address' => ''])->assertUnprocessable()->assertJsonValidationErrors(['name', 'address']);
        $this->postJson('/api/buildings', ['name' => 'برج النيل', 'address' => 'القاهرة', 'floors' => -1])->assertUnprocessable()->assertJsonValidationErrors('floors');
        $create = $this->postJson('/api/buildings', ['name' => 'برج النيل', 'address' => 'شارع النيل، القاهرة'])->assertCreated()->assertJsonPath('data.units_count', 0)->assertJsonPath('data.is_active', true);
        $id = $create->json('data.id');
        foreach (['available', 'rented', 'sold'] as $i => $status) {
            Unit::create(['building_id' => $id, 'number' => (string) $i, 'status' => $status]);
        }
        $this->getJson('/api/buildings/'.$id)->assertOk()->assertJsonPath('data.units_count', 3)->assertJsonPath('data.status_counts.available', 1)->assertJsonPath('data.status_counts.rented', 1)->assertJsonPath('data.status_counts.sold', 1);
        $this->putJson('/api/buildings/'.$id, ['name' => 'برج النيل الجديد', 'address' => 'القاهرة', 'is_active' => false, 'notes' => 'سجل محفوظ'])->assertOk()->assertJsonPath('data.is_active', false);
        $this->deleteJson('/api/buildings/'.$id)->assertStatus(409);
        $this->assertDatabaseHas('buildings', ['id' => $id, 'is_active' => false]);
        $this->assertDatabaseCount('units', 3);
        $this->getJson('/api/buildings/'.$id.'/units')->assertOk()->assertJsonCount(3, 'data');
        $this->getJson('/api/buildings/99999')->assertNotFound()->assertJsonStructure(['message']);
    }

    public function test_search_status_pagination_and_unit_isolation(): void
    {
        Sanctum::actingAs($this->owner());
        $a = Building::create(['name' => 'برج النيل', 'address' => 'القاهرة']);
        $b = Building::create(['name' => 'برج البحر', 'address' => 'الإسكندرية', 'is_active' => false]);
        Unit::create(['building_id' => $a->id, 'number' => '١٠١']);
        $this->getJson('/api/buildings?search='.urlencode('النيل'))->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $a->id);
        $this->getJson('/api/buildings?search='.urlencode('الإسكندرية'))->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $b->id);
        $this->getJson('/api/buildings?status=inactive')->assertJsonCount(1, 'data');
        $this->getJson('/api/buildings/'.$b->id.'/units')->assertJsonCount(0, 'data');
        for ($i = 0; $i < 13; $i++) {
            Building::create(['name' => 'مبنى '.$i, 'address' => 'القاهرة']);
        }
        $this->getJson('/api/buildings')->assertJsonCount(12, 'data')->assertJsonPath('meta.total', 15);
        $this->getJson('/api/buildings?page=2')->assertJsonCount(3, 'data');
        $this->getJson('/api/buildings?status=invalid')->assertUnprocessable();
    }

    public function test_owner_command_creates_a_hashed_password(): void
    {
        $this->artisan('app:create-owner')
            ->expectsQuestion('Owner name', 'المالك')
            ->expectsQuestion('Email', 'owner@example.com')
            ->expectsQuestion('Password (at least 12 characters)', 'a-strong-password')
            ->assertSuccessful();
        $owner = User::sole();
        $this->assertTrue(Hash::check('a-strong-password', $owner->password));
        $this->assertSame('owner@example.com', $owner->email);
    }

    public function test_owner_command_refuses_second_user(): void
    {
        $this->owner();
        $this->artisan('app:create-owner')->assertFailed();
        $this->assertDatabaseCount('users', 1);
    }
}
