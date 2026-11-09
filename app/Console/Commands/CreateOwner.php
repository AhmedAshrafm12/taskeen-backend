<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;

class CreateOwner extends Command
{
    protected $signature = 'app:create-owner';

    protected $description = 'Create the sole property owner login interactively';

    public function handle(): int
    {
        if (User::exists()) {
            $this->error('An owner already exists. No additional users may be created.');

            return self::FAILURE;
        }
        $data = ['name' => $this->ask('Owner name'), 'email' => $this->ask('Email'), 'password' => $this->secret('Password (at least 12 characters)')];
        $validator = Validator::make($data, ['name' => 'required|string|max:255', 'email' => 'required|email|max:255', 'password' => 'required|string|min:12|max:255']);
        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

return self::FAILURE;
        }
        User::create($data);
        $this->info('Owner created. You can now sign in.');

        return self::SUCCESS;
    }
}
