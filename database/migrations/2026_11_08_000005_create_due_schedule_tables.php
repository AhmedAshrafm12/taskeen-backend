<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('schedules', function (Blueprint $t) {
            $t->id();
            $t->foreignId('contract_id')->constrained()->restrictOnDelete();
            $t->unsignedInteger('version');
            $t->string('mode', 20);
            $t->string('policy')->default('actual_days_final_period_v1');
            $t->text('reason');
            $t->foreignId('user_id')->constrained()->restrictOnDelete();
            $t->uuid('request_key')->nullable()->unique();
            $t->string('request_hash', 64)->nullable();
            $t->timestamps();
            $t->unique(['contract_id', 'version']);
        });
        Schema::create('dues', function (Blueprint $t) {
            $t->id();
            $t->foreignId('schedule_id')->constrained()->restrictOnDelete();
            $t->foreignId('contract_id')->constrained()->restrictOnDelete();
            $t->unsignedInteger('sequence');
            $t->string('kind', 30);
            $t->string('label', 200);
            $t->date('due_date');
            $t->decimal('amount', 14, 2);
            $t->date('period_start')->nullable();
            $t->date('period_end')->nullable();
            $t->boolean('is_void')->default(false);
            $t->timestamp('voided_at')->nullable();
            $t->text('void_reason')->nullable();
            $t->timestamps();
            $t->unique(['schedule_id', 'sequence']);
            $t->index(['is_void', 'due_date']);
            $t->index(['contract_id', 'kind']);
        });
        // Allocation storage for Step 7; there is no payment-write API in Step 6.
        Schema::create('payments', function (Blueprint $t) {
            $t->id();
            $t->foreignId('contract_id')->constrained()->restrictOnDelete();
            $t->foreignId('user_id')->constrained()->restrictOnDelete();
            $t->decimal('amount', 14, 2);
            $t->string('method', 20)->default('cash');
            $t->date('paid_on');
            $t->string('status', 20)->default('posted');
            $t->string('reference')->nullable();
            $t->text('notes')->nullable();
            $t->uuid('request_key')->unique();
            $t->string('request_hash', 64);
            $t->timestamp('reversed_at')->nullable();
            $t->foreignId('reversed_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->text('reversal_reason')->nullable();
            $t->timestamps();
        });
        Schema::create('payment_allocations', function (Blueprint $t) {
            $t->id();
            $t->foreignId('payment_id')->constrained()->restrictOnDelete();
            $t->foreignId('due_id')->constrained()->restrictOnDelete();
            $t->decimal('amount', 14, 2);
            $t->timestamps();
            $t->unique(['payment_id', 'due_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_allocations');
        Schema::dropIfExists('payments');
        Schema::dropIfExists('dues');
        Schema::dropIfExists('schedules');
    }
};
