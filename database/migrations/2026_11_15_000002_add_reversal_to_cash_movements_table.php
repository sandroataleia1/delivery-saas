<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Estorno de sangria ou suprimento: um lançamento novo que aponta para o original. Cada um só pode ser estornado uma vez. */
    public function up(): void
    {
        Schema::table('cash_movements', function (Blueprint $table) {
            $table->foreignId('reverses_movement_id')->nullable()->unique()->after('sale_id')->constrained('cash_movements')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('cash_movements', function (Blueprint $table) {
            $table->dropConstrainedForeignId('reverses_movement_id');
        });
    }
};
