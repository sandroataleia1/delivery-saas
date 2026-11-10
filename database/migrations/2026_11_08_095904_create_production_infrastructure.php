<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('production_sectors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->string('name', 60);
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->unique(['company_id', 'name']);
        });
        if (DB::getDriverName() === 'sqlite') {
            DB::statement('ALTER TABLE companies ADD COLUMN default_production_sector_id INTEGER REFERENCES production_sectors(id) ON DELETE RESTRICT');
            DB::statement('ALTER TABLE categories ADD COLUMN production_sector_id INTEGER REFERENCES production_sectors(id) ON DELETE RESTRICT');
        } else {
            Schema::table('companies', fn (Blueprint $table) => $table->foreignId('default_production_sector_id')->nullable()->constrained('production_sectors')->restrictOnDelete());
            Schema::table('categories', fn (Blueprint $table) => $table->foreignId('production_sector_id')->nullable()->constrained('production_sectors')->restrictOnDelete());
        }
        Schema::table('products', fn (Blueprint $table) => $table->boolean('sold_out')->default(false));
        Schema::create('production_works', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('table_order_item_id')->nullable()->unique()->constrained()->restrictOnDelete();
            $table->foreignId('delivery_order_item_id')->nullable()->unique()->constrained()->restrictOnDelete();
            $table->foreignId('production_sector_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('sector_name', 60)->nullable();
            $table->string('product_name');
            $table->string('unit', 10);
            $table->decimal('quantity', 12, 3);
            $table->text('notes')->nullable();
            $table->string('origin_label');
            $table->string('status', 20)->nullable();
            $table->unsignedInteger('version')->default(0);
            $table->boolean('legacy')->default(false);
            $table->timestamp('reconciled_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();
            $table->index(['company_id', 'production_sector_id', 'status'], 'production_panel');
        });
        if (DB::getDriverName() === 'sqlite') {
            DB::statement("CREATE TRIGGER production_origin_insert BEFORE INSERT ON production_works WHEN (NEW.table_order_item_id IS NULL) = (NEW.delivery_order_item_id IS NULL) BEGIN SELECT RAISE(ABORT, 'Exactly one production origin required'); END");
            DB::statement("CREATE TRIGGER production_origin_update BEFORE UPDATE ON production_works WHEN (NEW.table_order_item_id IS NULL) = (NEW.delivery_order_item_id IS NULL) BEGIN SELECT RAISE(ABORT, 'Exactly one production origin required'); END");
        } else {
            DB::statement('ALTER TABLE production_works ADD CONSTRAINT production_one_origin CHECK ((table_order_item_id IS NULL) <> (delivery_order_item_id IS NULL))');
        }
        Schema::create('production_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('production_work_id')->constrained()->restrictOnDelete();
            $table->string('from_state', 20)->nullable();
            $table->string('to_state', 20);
            $table->unsignedInteger('version');
            $table->foreignId('actor_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('actor_name')->nullable();
            $table->text('reason')->nullable();
            $table->foreignId('acknowledged_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('acknowledged_at')->nullable();
            $table->timestamps();
            $table->unique(['production_work_id', 'version']);
        });
        foreach (['table_order_items' => 'table_orders', 'delivery_order_items' => 'delivery_orders'] as $items => $orders) {
            $fk = $items === 'table_order_items' ? 'table_order_id' : 'delivery_order_id';
            $origin = $items === 'table_order_items' ? 'table_order_item_id' : 'delivery_order_item_id';
            $parents = DB::table($orders)->where($orders === 'table_orders' ? 'status' : 'sale_id', $orders === 'table_orders' ? 'open' : null);
            if ($orders === 'delivery_orders') {
                $parents->where('status', '<>', 'cancelled');
            }
            $query = DB::table($items)->whereIn($fk, $parents->select('id'));
            if ($items === 'table_order_items') {
                $query->whereNull('cancelled_at');
            }
            $query->orderBy('id')->chunkById(200, function ($lines) use ($origin, $fk, $orders) {
                foreach ($lines as $line) {
                    $parent = DB::table($orders)->find($line->$fk);
                    DB::table('production_works')->insert(['company_id' => $line->company_id, $origin => $line->id,
                        'product_name' => $line->product_name, 'unit' => $line->unit, 'quantity' => $line->quantity,
                        'notes' => $line->notes, 'origin_label' => ($orders === 'table_orders' ? 'Comanda ' : 'Entrega ').$parent->number,
                        'legacy' => true, 'created_at' => now(), 'updated_at' => now()]);
                }
            });
        }
        DB::table('roles')->orderBy('id')->chunkById(200, function ($roles) {
            foreach ($roles as $role) {
                $permissions = json_decode($role->permissions ?? '[]', true);
                if (in_array('tables.operate', $permissions, true)) {
                    DB::table('roles')->where('id', $role->id)->update(['permissions' => json_encode(array_values(array_unique([...$permissions, 'production.view', 'production.deliver'])))]);
                }
            }
        });
    }

    public function down(): void
    {
        if (DB::table('production_works')->exists() || DB::table('production_events')->exists()) {
            throw new RuntimeException('Rollback recusado: existe histórico de produção. Use migração corretiva.');
        }
        Schema::dropIfExists('production_events');
        Schema::dropIfExists('production_works');
        Schema::table('products', fn (Blueprint $table) => $table->dropColumn('sold_out'));
        Schema::table('categories', fn (Blueprint $table) => $table->dropConstrainedForeignId('production_sector_id'));
        Schema::table('companies', fn (Blueprint $table) => $table->dropConstrainedForeignId('default_production_sector_id'));
        Schema::dropIfExists('production_sectors');
    }
};
