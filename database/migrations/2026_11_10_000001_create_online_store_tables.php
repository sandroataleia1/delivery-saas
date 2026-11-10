<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TABLES = ['online_order_items', 'online_orders', 'online_store_hours', 'online_stores'];

    public function up(): void
    {
        Schema::create('online_stores', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->unique()->constrained()->restrictOnDelete();
            $table->boolean('enabled')->default(false);
            $table->string('slug', 60)->unique();
            $table->boolean('accepts_delivery')->default(true);
            $table->boolean('accepts_pickup')->default(true);
            $table->decimal('min_order', 10, 2)->default(0);
            $table->string('eta_text', 60)->nullable();
            $table->string('whatsapp', 20)->nullable();
            $table->unsignedSmallInteger('accept_minutes')->default(15);
            $table->string('logo_path')->nullable();
            $table->timestamp('paused_at')->nullable();
            $table->string('paused_by_name')->nullable();
            $table->string('paused_reason', 120)->nullable();
            $table->timestamps();
        });
        Schema::create('online_store_hours', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->unsignedTinyInteger('weekday');
            $table->time('opens_at');
            $table->time('closes_at');
            $table->index(['company_id', 'weekday']);
        });
        Schema::create('online_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('number');
            $table->string('client_uuid', 64);
            $table->char('content_hash', 64);
            $table->char('tracking_token_hash', 64)->unique();
            $table->text('tracking_token');
            $table->string('status', 20);
            $table->string('type', 20);
            $table->foreignId('customer_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('customer_name');
            $table->string('customer_phone', 20);
            $table->string('street')->nullable();
            $table->string('number_address', 20)->nullable();
            $table->string('complement')->nullable();
            $table->string('district')->nullable();
            $table->string('reference')->nullable();
            $table->foreignId('delivery_zone_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('zone_name')->nullable();
            $table->decimal('subtotal', 10, 2);
            $table->decimal('delivery_fee', 10, 2)->default(0);
            $table->decimal('total', 10, 2);
            $table->string('payment_method', 20);
            $table->decimal('change_for', 10, 2)->nullable();
            $table->string('notes', 255)->nullable();
            $table->timestamp('expires_at');
            $table->timestamp('decided_at')->nullable();
            $table->foreignId('decided_by_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('decided_by_name')->nullable();
            $table->string('decision_reason', 255)->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'number']);
            $table->unique(['company_id', 'client_uuid']);
            $table->index(['company_id', 'status', 'expires_at']);
        });
        Schema::create('online_order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('online_order_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->string('product_name');
            $table->string('unit', 10)->nullable();
            $table->unsignedInteger('quantity');
            $table->decimal('unit_price', 10, 2);
            $table->decimal('total', 10, 2);
            $table->json('options')->nullable();
            $table->string('options_label', 255)->nullable();
            $table->string('notes', 120)->nullable();
        });
        Schema::table('delivery_orders', function (Blueprint $table) {
            $table->foreignId('online_order_id')->nullable()->unique()->constrained()->restrictOnDelete();
        });
        Schema::table('products', fn (Blueprint $table) => $table->string('image_path')->nullable());
    }

    public function down(): void
    {
        foreach (self::TABLES as $table) {
            if (DB::table($table)->exists()) {
                throw new RuntimeException('Rollback recusado: existem dados da loja online. Use migração corretiva.');
            }
        }
        Schema::table('products', fn (Blueprint $table) => $table->dropColumn('image_path'));
        Schema::table('delivery_orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('online_order_id');
        });
        foreach (self::TABLES as $table) {
            Schema::dropIfExists($table);
        }
    }
};
