<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Com o aceite automático ligado, o pedido da loja vira entrega e vai para a produção sem esperar a equipe. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('online_stores', function (Blueprint $table) {
            $table->boolean('auto_accept')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('online_stores', function (Blueprint $table) {
            $table->dropColumn('auto_accept');
        });
    }
};
