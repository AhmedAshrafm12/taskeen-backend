<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('buildings', function (Blueprint $table) {
            $table->id();
            $table->string('name', 150);
            $table->string('address', 500);
            $table->unsignedSmallInteger('floors')->nullable();
            $table->text('notes')->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();
        });
        // Foundation for building counts; unit management is delivered in step 3.
        Schema::create('units', function (Blueprint $table) {
            $table->id();
            $table->foreignId('building_id')->constrained()->restrictOnDelete();
            $table->string('number', 50);
            $table->integer('floor')->default(0);
            $table->string('type', 20)->default('apartment');
            $table->decimal('area', 10, 2)->nullable();
            $table->string('status', 20)->default('available');
            $table->timestamps();
            $table->unique(['building_id', 'number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('units');
        Schema::dropIfExists('buildings');
    }
};
