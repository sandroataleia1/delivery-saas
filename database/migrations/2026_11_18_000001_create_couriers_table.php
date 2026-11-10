<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Cadastro de entregadores, com ou sem login, e o registro de entrega que voltou. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('couriers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name', 80);
            $table->string('phone', 20)->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->unique(['company_id', 'user_id']);
            $table->index(['company_id', 'active']);
        });

        Schema::table('delivery_orders', function (Blueprint $table) {
            $table->foreignId('delivery_courier_id')->nullable()->after('courier_name')->constrained('couriers')->nullOnDelete();
            $table->timestamp('returned_at')->nullable()->after('delivered_at');
            $table->string('return_reason')->nullable()->after('returned_at');
        });
    }

    public function down(): void
    {
        Schema::table('delivery_orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('delivery_courier_id');
            $table->dropColumn(['returned_at', 'return_reason']);
        });

        Schema::dropIfExists('couriers');
    }
};
