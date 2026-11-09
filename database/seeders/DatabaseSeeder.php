<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            $this->command?->warn('Demo login is only seeded in local/testing environments.');

            return;
        }

        if (User::exists()) {
            $this->command?->info('An owner already exists; no login was added or changed.');

            return;
        }

        User::create([
            'name' => 'مالك تجريبي',
            'username' => 'owner',
            'email' => 'owner@example.com',
            'password' => 'TaskeenDemo123!',
            'email_verified_at' => now(),
        ]);
    }
}
