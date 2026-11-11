<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('restaurant_operation_settings', function (Blueprint $table) {
            $table->string('waiter_notices', 20)->default('all');
        });
    }

    public function down(): void
    {
        Schema::table('restaurant_operation_settings', function (Blueprint $table) {
            $table->dropColumn('waiter_notices');
        });
    }
};
