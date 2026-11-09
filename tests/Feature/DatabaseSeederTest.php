<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class DatabaseSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_demo_owner_can_login_and_reseeding_preserves_the_account(): void
    {
        $this->seed(DatabaseSeeder::class);
        $passwordHash = User::sole()->password;
        $this->assertTrue(Hash::check('TaskeenDemo123!', $passwordHash));
        $this->seed(DatabaseSeeder::class);
        $this->assertSame($passwordHash, User::sole()->password);
        $this->postJson('/api/auth/login', [
            'login' => 'owner',
            'password' => 'TaskeenDemo123!',
        ])->assertOk()->assertJsonPath('data.user.username', 'owner');
        $this->postJson('/api/auth/login', [
            'login' => 'owner@example.com',
            'password' => 'TaskeenDemo123!',
        ])->assertOk()->assertJsonStructure(['data' => ['token', 'user']]);
    }

    public function test_existing_owner_is_not_replaced_or_given_an_additional_login(): void
    {
        $owner = User::factory()->create();
        $this->seed(DatabaseSeeder::class);
        $this->assertSame($owner->id, User::sole()->id);
        $this->assertSame($owner->password, User::sole()->password);
    }

    public function test_demo_owner_is_not_seeded_in_production(): void
    {
        $this->app->instance('env', 'production');
        $this->app->make(DatabaseSeeder::class)->run();
        $this->assertDatabaseCount('users', 0);
    }
}
