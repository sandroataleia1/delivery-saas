<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Cada papel pode sair sozinho (automático) ou só quando alguém aperta o botão de imprimir (manual).
     */
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->boolean('auto_print_orders')->default(true)->after('order_printer_id');
            $table->boolean('auto_print_kitchen')->default(true)->after('auto_print_orders');
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn(['auto_print_orders', 'auto_print_kitchen']);
        });
    }
};
