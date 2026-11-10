<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Quando a mesa pediu a conta: a comanda segue aberta, mas o salão vê a mesa "em fechamento".
     */
    public function up(): void
    {
        Schema::table('table_orders', function (Blueprint $table) {
            $table->timestamp('bill_requested_at')->nullable()->after('opened_at');
        });
    }

    public function down(): void
    {
        Schema::table('table_orders', function (Blueprint $table) {
            $table->dropColumn('bill_requested_at');
        });
    }
};
