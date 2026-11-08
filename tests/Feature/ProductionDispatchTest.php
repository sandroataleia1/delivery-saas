<?php

namespace Tests\Feature;

use App\Actions\AddOrderItems;
use App\Actions\CreateDeliveryOrder;
use App\Actions\OpenTable;
use App\Models\Customer;
use App\Models\DiningTable;
use App\Models\MenuOrderReceipt;
use App\Models\Product;
use App\Models\ProductionSector;
use App\Models\ProductionWork;
use App\Models\Sale;
use App\Models\StockMovement;
use App\Models\TableOrderItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ProductionDispatchTest extends TestCase
{
    use RefreshDatabase;

    public function test_staff_dispatch_is_atomic_and_idempotent_without_stock_or_money(): void
    {
        $admin = $this->adminOf();
        $this->actingAs($admin);
        $sector = ProductionSector::factory()->create(['company_id' => $admin->company_id, 'name' => 'Bar']);
        $admin->company->forceFill(['default_production_sector_id' => $sector->id])->save();
        $product = Product::factory()->create(['company_id' => $admin->company_id, 'stock_quantity' => '12']);
        $table = DiningTable::factory()->create(['company_id' => $admin->company_id]);
        $order = app(OpenTable::class)->handle($table, 1, $admin);
        $uuid = (string) Str::uuid();
        $items = [['product_id' => $product->id, 'quantity' => '1.500', 'notes' => 'Sem gelo']];
        app(AddOrderItems::class)->handle($order, $items, $uuid);
        app(AddOrderItems::class)->handle($order, $items, $uuid);
        $work = ProductionWork::sole();
        $this->assertSame('waiting', $work->status);
        $this->assertSame('1.500', $work->quantity);
        $this->assertSame('Bar', $work->sector_name);
        $this->assertSame(1, TableOrderItem::count());
        $this->assertSame(0, Sale::count());
        $this->assertSame(0, StockMovement::count());
        $this->assertSame('12.000', $product->fresh()->stock_quantity);
    }

    public function test_missing_destination_rolls_back_item(): void
    {
        $admin = $this->adminOf();
        $this->actingAs($admin);
        $product = Product::factory()->create(['company_id' => $admin->company_id]);
        $order = app(OpenTable::class)->handle(DiningTable::factory()->create(['company_id' => $admin->company_id]), 1, $admin);
        try {
            app(AddOrderItems::class)->handle($order, [['product_id' => $product->id, 'quantity' => '1']], (string) Str::uuid());
            $this->fail('Destino ausente deveria recusar lanÃ§amento.');
        } catch (ValidationException $error) {
            $this->assertArrayHasKey('production', $error->errors());
        }
        $this->assertSame(0, TableOrderItem::count());
        $this->assertSame(0, ProductionWork::count());
        $this->assertSame(0, Sale::count());
        $this->assertSame(0, StockMovement::count());
    }

    public function test_qr_confirmation_dispatches_own_lines_and_replay_survives_sold_out(): void
    {
        $admin = $this->adminOf();
        $sector = ProductionSector::factory()->create(['company_id' => $admin->company_id]);
        $admin->company->forceFill(['default_production_sector_id' => $sector->id, 'qr_menu_enabled' => true])->save();
        $product = Product::factory()->create(['company_id' => $admin->company_id]);
        $table = DiningTable::factory()->create(['company_id' => $admin->company_id]);
        $data = ['service_context' => $table->service_context, 'client_uuid' => (string) Str::uuid(), 'tracking_key' => str_repeat('a', 64), 'items' => [['product_id' => $product->id, 'quantity' => 1]]];
        $this->postJson('/m/'.$table->qr_token, $data)->assertCreated();
        $receipt = MenuOrderReceipt::sole();
        $this->assertSame(0, ProductionWork::count());
        $this->actingAs($admin)->post('/mesas/recebimentos/'.$receipt->id.'/confirmar')->assertSessionHasNoErrors();
        $this->assertSame(1, ProductionWork::count());
        $product->forceFill(['sold_out' => true])->save();
        $this->postJson('/m/'.$table->qr_token, $data)->assertOk()->assertJsonPath('receipt.status', 'confirmed');
        $data['client_uuid'] = (string) Str::uuid();
        $this->postJson('/m/'.$table->qr_token, $data)->assertUnprocessable()->assertJsonValidationErrors('items.0.product_id');
        $this->assertSame(1, ProductionWork::count());
        $this->assertSame(0, Sale::count());
        $this->assertSame(0, StockMovement::count());
    }

    public function test_delivery_dispatch_is_atomic_and_keeps_money_at_origin(): void
    {
        $admin = $this->adminOf();
        $this->actingAs($admin);
        $sector = ProductionSector::factory()->create(['company_id' => $admin->company_id]);
        $admin->company->forceFill(['default_production_sector_id' => $sector->id])->save();
        $product = Product::factory()->create(['company_id' => $admin->company_id]);
        $customer = Customer::factory()->create(['company_id' => $admin->company_id]);
        $data = ['client_uuid' => (string) Str::uuid(), 'customer_id' => $customer->id, 'type' => 'pickup', 'payment_method' => 'pix', 'items' => [['product_id' => $product->id, 'quantity' => '2']]];
        $action = app(CreateDeliveryOrder::class);
        $order = $action->handle($data);
        $action->handle($data);
        $work = ProductionWork::sole();
        $this->assertNotNull($work->delivery_order_item_id);
        $this->assertNull($work->table_order_item_id);
        $this->assertSame('20.00', $order->subtotal);
        $this->assertSame(0, Sale::count());
        $this->assertSame(0, StockMovement::count());
    }
}
