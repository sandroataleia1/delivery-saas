<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('restaurant_operation_settings', function (Blueprint $table) {
            $table->boolean('automatic_enabled')->default(false);
            $table->timestamp('automatic_accepted_at')->nullable();
            $table->foreignId('automatic_accepted_by_id')->nullable()->constrained('users')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        if (DB::table('restaurant_operation_settings')->whereNotNull('automatic_accepted_at')->exists()) {
            throw new RuntimeException('Existe aceite de produção automática; use migração corretiva.');
        }
        Schema::table('restaurant_operation_settings', function (Blueprint $table) {
            $table->dropConstrainedForeignId('automatic_accepted_by_id');
            $table->dropColumn(['automatic_enabled', 'automatic_accepted_at']);
        });
    }
};
