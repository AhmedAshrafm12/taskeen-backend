<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contracts', function (Blueprint $t) {
            $t->id();
            $t->foreignId('unit_id')->constrained()->restrictOnDelete();
            $t->foreignId('customer_id')->constrained()->restrictOnDelete();
            $t->foreignId('renewed_from_id')->nullable()->constrained('contracts')->restrictOnDelete();
            $t->string('type', 20);
            $t->string('status', 20);
            $t->string('sale_mode', 20)->nullable();
            $t->date('start_date');
            $t->date('end_date')->nullable();
            $t->date('effective_end_date')->nullable();
            $t->decimal('amount', 14, 2);
            $t->decimal('down_payment', 14, 2)->default(0);
            $t->decimal('security_deposit', 14, 2)->default(0);
            $t->string('frequency', 20)->nullable();
            $t->unsignedInteger('installments_count')->nullable();
            $t->date('first_due_date')->nullable();
            $t->text('notes')->nullable();
            $t->timestamp('activated_at')->nullable();
            $t->uuid('request_key')->unique();
            $t->string('request_hash', 64);
            $t->timestamps();
            $t->index(['unit_id', 'status', 'type']);
            $t->index(['customer_id', 'start_date']);
        });
        Schema::create('contract_events', function (Blueprint $t) {
            $t->id();
            $t->foreignId('contract_id')->constrained()->restrictOnDelete();
            $t->foreignId('user_id')->constrained()->restrictOnDelete();
            $t->string('action', 30);
            $t->string('from_status', 20)->nullable();
            $t->string('to_status', 20);
            $t->text('reason')->nullable();
            $t->date('effective_date')->nullable();
            $t->uuid('request_key')->nullable()->unique();
            $t->string('request_hash', 64)->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contract_events');
        Schema::dropIfExists('contracts');
    }
};
