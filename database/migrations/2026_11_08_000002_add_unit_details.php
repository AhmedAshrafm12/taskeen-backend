<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('units', function (Blueprint $table) {
            $table->decimal('asking_sale_price', 14, 2)->nullable();
            $table->decimal('asking_rent_price', 14, 2)->nullable();
            $table->text('notes')->nullable();
            $table->index(['building_id', 'status']);
            $table->index('type');
        });
    }

    public function down(): void
    {
        Schema::table('units', function (Blueprint $table) {
            $table->dropIndex(['building_id', 'status']);
            $table->dropIndex(['type']);
            $table->dropColumn(['asking_sale_price', 'asking_rent_price', 'notes']);
        });
    }
};
