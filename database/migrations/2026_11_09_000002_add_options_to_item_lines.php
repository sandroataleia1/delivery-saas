<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TABLES = ['sale_items', 'table_order_items', 'delivery_order_items', 'menu_order_receipt_items', 'production_works'];

    public function up(): void
    {
        foreach (self::TABLES as $table) {
            Schema::table($table, function (Blueprint $table) {
                $table->json('options')->nullable();
                $table->string('options_label', 255)->nullable();
            });
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $table) {
            if (DB::table($table)->whereNotNull('options')->exists()) {
                throw new RuntimeException('Rollback recusado: existem linhas com opções gravadas. Use migração corretiva.');
            }
        }
        foreach (self::TABLES as $table) {
            Schema::table($table, fn (Blueprint $table) => $table->dropColumn(['options', 'options_label']));
        }
    }
};
