<?php

namespace App\Console\Commands;

use App\Models\Contract;
use App\Models\Unit;
use App\Models\User;
use App\Services\ScheduleService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class GenerateSchedules extends Command
{
    protected $signature = 'app:generate-schedules';

    protected $description = 'Generate missing schedules for existing activated contracts without modifying existing revisions';

    public function handle(ScheduleService $service): int
    {
        $user = User::first();
        if (! $user) {
            $this->error('Create the owner first.');

            return self::FAILURE;
        }
        $created = 0;
        Contract::whereNotNull('activated_at')->whereNotIn('status', ['draft', 'cancelled'])->orderBy('id')->chunkById(100, function ($contracts) use ($service, $user, &$created) {
            foreach ($contracts as $original) {
                DB::transaction(function () use ($original, $service, $user, &$created) {
                    Unit::lockForUpdate()->findOrFail($original->unit_id);
                    $c = Contract::lockForUpdate()->findOrFail($original->id);
                    if (! $c->schedules()->exists() && ! in_array($c->status, ['draft', 'cancelled'])) {
                        $service->ensure($c, $user->id);
                        $created++;
                    }
                }, 3);
            }
        });
        $this->info('Generated '.$created.' missing schedules. Existing schedules were preserved.');

        return self::SUCCESS;
    }
}
