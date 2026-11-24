<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contracts', function (Blueprint $t) {
            $t->decimal('credit_balance', 14, 2)->default(0)->after('security_deposit');
        });
        Schema::create('contract_revisions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('contract_id')->constrained()->restrictOnDelete();
            $t->foreignId('user_id')->constrained()->restrictOnDelete();
            $t->text('reason');
            $t->uuid('request_key')->unique();
            $t->string('request_hash', 64);
            $t->json('before');
            $t->json('after');
            $t->json('options');
            $t->timestamps();
        });
        Schema::table('contract_events', function (Blueprint $t) {
            $t->foreignId('revision_id')->nullable()->after('effective_date')->constrained('contract_revisions')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('contract_events', function (Blueprint $t) {
            $t->dropConstrainedForeignId('revision_id');
        });
        Schema::dropIfExists('contract_revisions');
        Schema::table('contracts', function (Blueprint $t) {
            $t->dropColumn('credit_balance');
        });
    }
};
