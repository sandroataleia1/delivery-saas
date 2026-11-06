<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dining_tables', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('number');
            $table->string('area', 40)->nullable();
            $table->unsignedSmallInteger('seats')->default(4);
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->unique(['company_id', 'number']);
        });

        Schema::create('table_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('number');
            $table->foreignId('dining_table_id')->constrained()->restrictOnDelete();
            // Preenchido só enquanto aberta: o índice único garante uma comanda por mesa.
            $table->unsignedBigInteger('open_table_id')->nullable();
            $table->unsignedSmallInteger('people')->default(1);
            $table->string('status', 20)->default('open');
            $table->unsignedInteger('kitchen_batch')->default(0);
            $table->foreignId('sale_id')->nullable()->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('user_name');
            $table->timestamp('opened_at');
            $table->timestamp('closed_at')->nullable();
            $table->string('closed_by')->nullable();
            $table->string('cancel_reason', 255)->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'number']);
            $table->unique(['company_id', 'open_table_id']);
            $table->index(['company_id', 'status', 'opened_at']);
        });

        Schema::create('table_order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('table_order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            // Identifica o envio: tocar duas vezes em "lançar" não duplica o pedido.
            $table->uuid('client_uuid');
            // Preço de quando foi pedido: o que o cliente viu no cardápio.
            $table->string('product_name', 120);
            $table->string('unit', 5);
            $table->decimal('quantity', 12, 3);
            $table->decimal('unit_price', 12, 2);
            $table->decimal('total', 12, 2);
            $table->string('notes', 120)->nullable();
            $table->unsignedInteger('kitchen_batch')->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('user_name');
            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancelled_by')->nullable();
            $table->string('cancel_reason', 255)->nullable();
            $table->timestamps();

            $table->index(['table_order_id', 'client_uuid']);
        });

        Schema::table('sales', function (Blueprint $table) {
            $table->string('origin', 20)->default('counter')->after('cash_session_id');
            $table->unsignedInteger('table_number')->nullable()->after('origin');
            $table->decimal('service_fee', 12, 2)->default(0)->after('discount');
        });

        Schema::table('companies', function (Blueprint $table) {
            $table->decimal('service_fee_percent', 5, 2)->default(10)->after('state');
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn('service_fee_percent');
        });

        Schema::table('sales', function (Blueprint $table) {
            $table->dropColumn(['origin', 'table_number', 'service_fee']);
        });

        Schema::dropIfExists('table_order_items');
        Schema::dropIfExists('table_orders');
        Schema::dropIfExists('dining_tables');
    }
};
