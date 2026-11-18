<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lands', function (Blueprint $table) {
            $table->id();
            $table->string('name', 150);
            $table->string('location', 500);
            $table->decimal('area', 12, 2)->nullable();
            $table->date('purchase_date')->nullable();
            $table->decimal('purchase_price', 14, 2)->default(0);
            $table->string('status', 20)->default('vacant'); // vacant | linked
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::table('buildings', function (Blueprint $table) {
            $table->foreignId('land_id')->nullable()->after('id')->constrained('lands')->nullOnDelete();
        });

        Schema::create('capital_transactions', function (Blueprint $table) {
            $table->id();
            $table->string('type', 32); // opening_balance | capital_addition | owner_withdrawal
            $table->decimal('amount', 14, 2);
            $table->date('occurred_on');
            $table->string('reference', 255)->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->index('type');
        });

        Schema::create('land_purchase_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('land_id')->constrained('lands')->restrictOnDelete();
            $table->decimal('amount', 14, 2);
            $table->date('paid_on');
            $table->text('notes')->nullable();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->timestamps();
        });

        Schema::create('expenses', function (Blueprint $table) {
            $table->id();
            $table->string('category', 40); // materials | labor | permits | finishing | maintenance | other
            $table->string('description', 500);
            $table->decimal('amount', 14, 2);
            $table->date('occurred_on');
            $table->string('status', 20)->default('unpaid'); // unpaid | partial | paid
            $table->foreignId('land_id')->nullable()->constrained('lands')->nullOnDelete();
            $table->foreignId('building_id')->nullable()->constrained('buildings')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->timestamps();
        });

        Schema::create('expense_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('expense_id')->constrained('expenses')->restrictOnDelete();
            $table->decimal('amount', 14, 2);
            $table->date('paid_on');
            $table->text('notes')->nullable();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->timestamps();
        });

        Schema::create('other_incomes', function (Blueprint $table) {
            $table->id();
            $table->string('category', 40)->default('other');
            $table->string('description', 500);
            $table->decimal('amount', 14, 2);
            $table->date('occurred_on');
            $table->foreignId('land_id')->nullable()->constrained('lands')->nullOnDelete();
            $table->foreignId('building_id')->nullable()->constrained('buildings')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->timestamps();
        });

        Schema::create('cash_transactions', function (Blueprint $table) {
            $table->id();
            $table->string('direction', 8); // in | out
            $table->decimal('signed_amount', 14, 2); // positive for in, negative for out
            $table->date('occurred_on');
            $table->string('source_type', 40);
            $table->unsignedBigInteger('source_id');
            $table->string('label', 255);
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->unique(['source_type', 'source_id']);
            $table->index(['occurred_on', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cash_transactions');
        Schema::dropIfExists('other_incomes');
        Schema::dropIfExists('expense_payments');
        Schema::dropIfExists('expenses');
        Schema::dropIfExists('land_purchase_payments');
        Schema::dropIfExists('capital_transactions');
        Schema::table('buildings', function (Blueprint $table) {
            $table->dropConstrainedForeignId('land_id');
        });
        Schema::dropIfExists('lands');
    }
};
