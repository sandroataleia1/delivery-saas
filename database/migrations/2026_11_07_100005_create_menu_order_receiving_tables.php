<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('dining_tables', fn (Blueprint $table) => $table->string('service_context', 64)->nullable());
        DB::table('dining_tables')->orderBy('id')->chunkById(200, function ($tables) {
            foreach ($tables as $table) {
                DB::table('dining_tables')->where('id', $table->id)->update(['service_context' => Str::random(64)]);
            }
        });
        Schema::create('restaurant_operation_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->unique()->constrained()->restrictOnDelete();
            $table->string('confirmation_mode', 20)->default('manual');
            foreach (['max_lines' => 20, 'max_line_units' => 10, 'max_order_units' => 50, 'max_pending_orders' => 5, 'max_pending_units' => 100, 'late_after_minutes' => 10] as $field => $default) {
                $table->unsignedInteger($field)->default($default);
            }
            $table->decimal('max_order_value', 12, 2)->default(500);
            $table->decimal('max_pending_value', 12, 2)->default(1000);
            $table->boolean('visual_alerts')->default(true);
            $table->boolean('sound_alerts')->default(true);
            $table->boolean('automatic_blocked')->default(false);
            $table->string('automatic_block_reason', 255)->nullable();
            $table->unsignedBigInteger('automatic_blocked_by_id')->nullable();
            $table->timestamp('automatic_blocked_at')->nullable();
            $table->timestamps();
        });
        Schema::create('menu_order_receipts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('dining_table_id')->constrained()->restrictOnDelete();
            $table->foreignId('table_order_id')->constrained()->restrictOnDelete();
            $table->string('service_context', 64);
            $table->uuid('client_uuid');
            $table->string('content_hash', 64);
            $table->string('tracking_key_hash', 64);
            $table->string('status', 20)->default('pending');
            $table->unsignedInteger('units');
            $table->decimal('total', 12, 2);
            $table->json('policy_snapshot');
            $table->timestamp('decided_at')->nullable();
            $table->unsignedBigInteger('decided_by_id')->nullable();
            $table->string('decided_by')->nullable();
            $table->string('decision_reason', 255)->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'client_uuid']);
            $table->index(['company_id', 'table_order_id', 'status']);
            $table->index(['company_id', 'status', 'created_at']);
        });
        Schema::create('menu_order_receipt_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('menu_order_receipt_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->string('product_name');
            $table->string('unit', 10);
            $table->unsignedInteger('quantity');
            $table->decimal('unit_price', 12, 2);
            $table->decimal('total', 12, 2);
            $table->string('notes', 120)->nullable();
            $table->timestamps();
        });
        Schema::table('table_order_items', function (Blueprint $table) {
            $table->foreignId('menu_order_receipt_item_id')->nullable()->unique()->constrained()->restrictOnDelete();
        });
        DB::table('roles')->orderBy('id')->chunkById(200, function ($roles) {
            foreach ($roles as $role) {
                $permissions = json_decode($role->permissions ?? '[]', true) ?? [];
                if (in_array('tables.operate', $permissions, true) && ! in_array('tables.confirm', $permissions, true)) {
                    DB::table('roles')->where('id', $role->id)->update(['permissions' => json_encode([...$permissions, 'tables.confirm'])]);
                }
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::table('menu_order_receipts')->exists()) {
            throw new RuntimeException('Rollback recusado: existem recebimentos de pedidos.');
        }
        Schema::table('table_order_items', fn (Blueprint $table) => $table->dropConstrainedForeignId('menu_order_receipt_item_id'));
        Schema::dropIfExists('menu_order_receipt_items');
        Schema::dropIfExists('menu_order_receipts');
        Schema::dropIfExists('restaurant_operation_settings');
        Schema::table('dining_tables', fn (Blueprint $table) => $table->dropColumn('service_context'));
    }
};
