<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $t) {
            $t->json('receipt_snapshot')->nullable();
            $t->foreignId('corrects_payment_id')->nullable()->unique()->constrained('payments')->restrictOnDelete();
            $t->index(['status', 'paid_on']);
        });
        Schema::create('payment_events', function (Blueprint $t) {
            $t->id();
            $t->foreignId('payment_id')->constrained()->restrictOnDelete();
            $t->foreignId('user_id')->constrained()->restrictOnDelete();
            $t->string('action', 20);
            $t->text('reason')->nullable();
            $t->foreignId('replacement_payment_id')->nullable()->constrained('payments')->restrictOnDelete();
            $t->uuid('request_key')->nullable()->unique();
            $t->string('request_hash', 64)->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_events');
        Schema::table('payments', function (Blueprint $t) {
            $t->dropConstrainedForeignId('corrects_payment_id');
            $t->dropIndex(['status', 'paid_on']);
            $t->dropColumn('receipt_snapshot');
        });
    }
};
