<?php

namespace Tests\Feature;

use App\Actions\AddOrderItems;
use App\Actions\OpenTable;
use App\Models\DiningTable;
use App\Models\Product;
use App\Models\ProductionSector;
use App\Models\ProductionWork;
use App\Models\TableOrderItem;
use Illuminate\Support\Str;
use Tests\Support\DisposableDatabase;
use Tests\TestCase;

class ProductionLegacyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        DisposableDatabase::guardApplication($this->app);
        $this->artisan('migrate:fresh', ['--force' => true])->assertSuccessful();
    }

    protected function tearDown(): void
    {
        try {
            $this->artisan('migrate:fresh', ['--force' => true])->assertSuccessful();
        } finally {
            parent::tearDown();
        }
    }

    public function test_migration_marks_active_legacy_without_inferring_printed_delivery(): void
    {
        $migration = require database_path('migrations/2026_11_08_095904_create_production_infrastructure.php');
        $migration->down();
        $admin = $this->adminOf();
        $this->actingAs($admin);
        $product = Product::factory()->create(['company_id' => $admin->company_id]);
        $order = app(OpenTable::class)->handle(DiningTable::factory()->create(['company_id' => $admin->company_id]), 1, $admin);
        $item = new TableOrderItem;
        $item->forceFill(['company_id' => $admin->company_id, 'table_order_id' => $order->id, 'product_id' => $product->id, 'client_uuid' => (string) Str::uuid(), 'product_name' => $product->name, 'unit' => 'UN', 'quantity' => '1', 'unit_price' => '10', 'total' => '10', 'user_name' => $admin->name, 'kitchen_batch' => 3])->save();
        $migration->up();
        $work = ProductionWork::sole();
        $this->assertTrue($work->legacy);
        $this->assertNull($work->status);
        $this->assertNull($work->delivered_at);
        $this->postJson('/mesas/comandas/'.$order->id.'/cozinha')->assertUnprocessable();
        $sector = ProductionSector::factory()->create(['company_id' => $admin->company_id]);
        $admin->company->forceFill(['default_production_sector_id' => $sector->id])->save();
        $this->post('/producao/'.$work->id.'/reconciliar', ['observed' => 'delivered', 'reason' => 'Conferido na mesa pelo gerente'])->assertSessionHasNoErrors();
        $this->assertNotNull($work->fresh()->reconciled_at);
        $this->assertNotNull($work->fresh()->delivered_at);
        $this->assertSame('reconciled', $work->events()->sole()->to_state);
        $this->postJson('/producao/'.$work->id.'/reconciliar', ['observed' => 'waiting', 'reason' => 'Tentativa de repetir'])->assertUnprocessable();
        $this->expectException(\RuntimeException::class);
        $migration->down();
    }

    public function test_new_work_cannot_use_legacy_shortcut(): void
    {
        $admin = $this->adminOf();
        $this->actingAs($admin);
        $sector = ProductionSector::factory()->create(['company_id' => $admin->company_id]);
        $admin->company->forceFill(['default_production_sector_id' => $sector->id])->save();
        $product = Product::factory()->create(['company_id' => $admin->company_id]);
        $order = app(OpenTable::class)->handle(DiningTable::factory()->create(['company_id' => $admin->company_id]), 1, $admin);
        app(AddOrderItems::class)->handle($order, [['product_id' => $product->id, 'quantity' => '1']], (string) Str::uuid());
        $work = ProductionWork::sole();
        $this->postJson('/producao/'.$work->id.'/reconciliar', ['observed' => 'delivered', 'reason' => 'Atalho inválido'])->assertUnprocessable();
        $this->assertSame('waiting', $work->fresh()->status);
        $this->assertNull($work->fresh()->delivered_at);
    }
}
