<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('name', 120);
            $table->string('phone', 20)->nullable();
            $table->string('document', 14)->nullable();
            $table->string('notes', 255)->nullable();
            $table->boolean('allow_credit')->default(false);
            // Nulo: fiado sem limite.
            $table->decimal('credit_limit', 12, 2)->nullable();
            $table->unsignedSmallInteger('credit_days')->default(30);
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->index(['company_id', 'name']);
            $table->index(['company_id', 'phone']);
        });

        Schema::create('customer_addresses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->string('zip_code', 8)->nullable();
            $table->string('street');
            $table->string('number', 20);
            $table->string('complement', 100)->nullable();
            $table->string('district', 100);
            $table->string('city', 100)->nullable();
            $table->string('state', 2)->nullable();
            $table->string('reference', 150)->nullable();
            $table->timestamps();
        });

        Schema::create('delivery_zones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('name', 100);
            $table->decimal('fee', 12, 2);
            $table->timestamps();

            $table->unique(['company_id', 'name']);
        });

        Schema::table('sales', function (Blueprint $table) {
            $table->foreignId('customer_id')->nullable()->after('table_number')->constrained()->restrictOnDelete();
            $table->decimal('delivery_fee', 12, 2)->default(0)->after('service_fee');
        });

        Schema::create('delivery_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('number');
            // Gerado pelo navegador: reenviar o mesmo pedido não cria outro.
            $table->uuid('client_uuid');
            $table->foreignId('customer_id')->constrained()->restrictOnDelete();
            $table->string('type', 20);
            $table->string('status', 20);
            // Cópia do endereço: o histórico não muda se o cliente se mudar.
            $table->string('customer_name', 120);
            $table->string('customer_phone', 20)->nullable();
            $table->string('street')->nullable();
            $table->string('number_address', 20)->nullable();
            $table->string('complement', 100)->nullable();
            $table->string('district', 100)->nullable();
            $table->string('reference', 150)->nullable();
            $table->decimal('subtotal', 12, 2);
            $table->decimal('delivery_fee', 12, 2)->default(0);
            $table->string('payment_method', 20);
            $table->decimal('change_for', 12, 2)->nullable();
            $table->string('notes', 255)->nullable();
            $table->unsignedBigInteger('courier_id')->nullable();
            $table->string('courier_name')->nullable();
            $table->foreignId('sale_id')->nullable()->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('user_name');
            $table->timestamp('dispatched_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancelled_by')->nullable();
            $table->string('cancel_reason', 255)->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'number']);
            $table->unique(['company_id', 'client_uuid']);
            $table->index(['company_id', 'status', 'created_at']);
            $table->index(['company_id', 'courier_id', 'status']);
        });

        Schema::create('delivery_order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('delivery_order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->string('product_name', 120);
            $table->string('unit', 5);
            $table->decimal('quantity', 12, 3);
            $table->decimal('unit_price', 12, 2);
            $table->decimal('total', 12, 2);
            $table->string('notes', 120)->nullable();
            $table->timestamps();
        });

        Schema::table('bills', function (Blueprint $table) {
            $table->foreignId('customer_id')->nullable()->after('supplier_id')->constrained()->restrictOnDelete();
            $table->foreignId('sale_id')->nullable()->after('stock_entry_id')->constrained()->restrictOnDelete();
        });

        Schema::table('products', function (Blueprint $table) {
            $table->string('description', 255)->nullable()->after('name');
        });

        Schema::table('companies', function (Blueprint $table) {
            $table->boolean('qr_menu_enabled')->default(true)->after('service_fee_percent');
        });

        Schema::table('dining_tables', function (Blueprint $table) {
            $table->string('qr_token', 40)->nullable()->after('seats');
        });

        DB::table('dining_tables')->orderBy('id')->each(function ($table) {
            DB::table('dining_tables')->where('id', $table->id)->update(['qr_token' => Str::random(32)]);
        });

        Schema::table('dining_tables', function (Blueprint $table) {
            $table->unique('qr_token');
        });
    }

    public function down(): void
    {
        Schema::table('dining_tables', function (Blueprint $table) {
            $table->dropUnique(['qr_token']);
            $table->dropColumn('qr_token');
        });

        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn('qr_menu_enabled');
        });

        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('description');
        });

        Schema::table('bills', function (Blueprint $table) {
            $table->dropConstrainedForeignId('sale_id');
            $table->dropConstrainedForeignId('customer_id');
        });

        Schema::dropIfExists('delivery_order_items');
        Schema::dropIfExists('delivery_orders');

        Schema::table('sales', function (Blueprint $table) {
            $table->dropConstrainedForeignId('customer_id');
            $table->dropColumn('delivery_fee');
        });

        Schema::dropIfExists('delivery_zones');
        Schema::dropIfExists('customer_addresses');
        Schema::dropIfExists('customers');
    }
};
