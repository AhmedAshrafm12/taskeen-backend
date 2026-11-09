<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AuthProfileTest extends TestCase
{
    use RefreshDatabase;

    private function owner(array $overrides = []): User
    {
        return User::factory()->create(array_merge([
            'name' => 'مالك تجريبي',
            'username' => 'owner',
            'email' => 'owner@example.com',
            'password' => Hash::make('correct-password'),
        ], $overrides));
    }

    public function test_login_accepts_username_or_email(): void
    {
        $this->owner();

        $this->postJson('/api/auth/login', [
            'login' => 'owner',
            'password' => 'correct-password',
        ])->assertOk()->assertJsonPath('data.user.username', 'owner');

        $this->postJson('/api/auth/login', [
            'login' => 'owner@example.com',
            'password' => 'correct-password',
        ])->assertOk()->assertJsonPath('data.user.email', 'owner@example.com');

        $this->postJson('/api/auth/login', [
            'email' => 'owner@example.com',
            'password' => 'correct-password',
        ])->assertOk();

        $this->postJson('/api/auth/login', [
            'login' => 'owner',
            'password' => 'wrong',
        ])->assertUnprocessable()->assertJsonValidationErrors('login');
    }

    public function test_owner_can_update_name_username_and_password(): void
    {
        $user = $this->owner();
        Sanctum::actingAs($user);

        $this->putJson('/api/auth/profile', [
            'name' => '  أحمد المالك  ',
            'username' => 'Ahmed.Owner',
        ])->assertOk()
            ->assertJsonPath('data.name', 'أحمد المالك')
            ->assertJsonPath('data.username', 'ahmed.owner');

        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'name' => 'أحمد المالك',
            'username' => 'ahmed.owner',
        ]);

        $this->putJson('/api/auth/profile', [
            'name' => 'أحمد المالك',
            'username' => 'ahmed.owner',
            'current_password' => 'wrong',
            'password' => 'NewSecret123!',
            'password_confirmation' => 'NewSecret123!',
        ])->assertUnprocessable()->assertJsonValidationErrors('current_password');

        $this->putJson('/api/auth/profile', [
            'name' => 'أحمد المالك',
            'username' => 'ahmed.owner',
            'current_password' => 'correct-password',
            'password' => 'NewSecret123!',
            'password_confirmation' => 'NewSecret123!',
        ])->assertOk();

        $this->assertTrue(Hash::check('NewSecret123!', $user->fresh()->password));

        $this->postJson('/api/auth/login', [
            'login' => 'ahmed.owner',
            'password' => 'NewSecret123!',
        ])->assertOk();
    }

    public function test_username_must_be_unique_and_valid(): void
    {
        $this->owner(['username' => 'taken']);
        $user = $this->owner([
            'username' => 'mine',
            'email' => 'other@example.com',
        ]);
        Sanctum::actingAs($user);

        $this->putJson('/api/auth/profile', [
            'name' => 'مالك',
            'username' => 'taken',
        ])->assertUnprocessable()->assertJsonValidationErrors('username');

        $this->putJson('/api/auth/profile', [
            'name' => 'مالك',
            'username' => '1bad',
        ])->assertUnprocessable()->assertJsonValidationErrors('username');
    }
}
