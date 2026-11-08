<?php

namespace Tests\Feature;

use App\Actions\AddOrderItems;
use App\Actions\OpenTable;
use App\Actions\RegisterSale;
use App\Models\Category;
use App\Models\DiningTable;
use App\Models\Product;
use App\Models\ProductionSector;
use App\Models\ProductionWork;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ProductionEndpointsTest extends TestCase
{
    use RefreshDatabase;

    public function test_sector_default_and_tenant_validation(): void
    {
        $admin = $this->adminOf();
        $this->actingAs($admin);
        $this->post('/producao/setores', ['name' => 'Cozinha', 'active' => true, 'is_default' => true])->assertSessionHasNoErrors();
        $sector = ProductionSector::sole();
        $this->assertSame($sector->id, $admin->company->fresh()->default_production_sector_id);
        $other = $this->adminOf();
        $this->actingAs($other)->put('/producao/setores/'.$sector->id, ['name' => 'Invadido', 'active' => true])->assertNotFound();
        $this->assertSame('Cozinha', $sector->fresh()->name);
        $this->actingAs($this->userWith([], $admin->company))->get('/producao')->assertForbidden();
    }

    public function test_panel_has_only_operational_data_and_cannot_mutate_another_tenant(): void
    {
        $admin = $this->adminOf();
        $this->actingAs($admin);
        $sector = ProductionSector::factory()->create(['company_id' => $admin->company_id]);
        $admin->company->forceFill(['default_production_sector_id' => $sector->id])->save();
        $product = Product::factory()->create(['company_id' => $admin->company_id]);
        $order = app(OpenTable::class)->handle(DiningTable::factory()->create(['company_id' => $admin->company_id]), 1, $admin);
        app(AddOrderItems::class)->handle($order, [['product_id' => $product->id, 'quantity' => '1']], (string) Str::uuid());
        $work = ProductionWork::sole();
        $cook = $this->userWith(['production.view', 'production.operate'], $admin->company);
        $this->actingAs($cook)->get('/producao')->assertOk()->assertInertia(fn (Assert $p) => $p->component('production/Index')->has('works', 1)->missing('works.0.unit_price')->missing('works.0.total')->missing('works.0.customer_phone')->missing('works.0.street'));
        $foreign = $this->adminOf();
        $this->actingAs($foreign)->post('/producao/'.$work->id.'/etapa', ['target' => 'preparing', 'expected_version' => 0])->assertNotFound();
        $this->assertSame('waiting', $work->fresh()->status);
        $this->actingAs($admin)->put('/producao/setores/'.$sector->id, ['name' => $sector->name, 'active' => false])->assertSessionHasErrors('active');
        $this->assertTrue($sector->fresh()->active);
    }

    public function test_category_rejects_foreign_sector_and_snapshots_keep_original_destination(): void
    {
        $admin = $this->productionAdminOf();
        $this->actingAs($admin);
        $foreign = ProductionSector::factory()->create();
        $this->postJson('/categorias', ['name' => 'Bebidas', 'production_sector_id' => $foreign->id])->assertUnprocessable()->assertJsonValidationErrors('production_sector_id');
        $this->assertSame(0, Category::count());
        $product = Product::factory()->create(['company_id' => $admin->company_id]);
        $order = app(OpenTable::class)->handle(DiningTable::factory()->create(['company_id' => $admin->company_id]), 1, $admin);
        app(AddOrderItems::class)->handle($order, [['product_id' => $product->id, 'quantity' => '1']], (string) Str::uuid());
        $work = ProductionWork::sole();
        $sector = ProductionSector::findOrFail($work->production_sector_id);
        $this->put('/producao/setores/'.$sector->id, ['name' => 'Nome novo', 'active' => true])->assertSessionHasNoErrors();
        $this->assertNotSame('Nome novo', $work->fresh()->sector_name);
        $this->delete('/producao/setores/'.$sector->id)->assertSessionHasErrors('sector');
        $this->assertNotNull($sector->fresh());
    }

    public function test_sold_out_toggle_blocks_new_restaurant_item_but_preserves_direct_pos_sale(): void
    {
        $admin = $this->productionAdminOf();
        $this->actingAs($admin);
        $this->openCash($admin);
        $product = Product::factory()->create(['company_id' => $admin->company_id, 'stock_quantity' => '10']);
        $this->patchJson('/produtos/'.$product->id.'/esgotado', ['sold_out' => true])->assertSuccessful();
        $this->assertTrue($product->fresh()->sold_out);
        $order = app(OpenTable::class)->handle(DiningTable::factory()->create(['company_id' => $admin->company_id]), 1, $admin);
        $this->postJson('/mesas/comandas/'.$order->id.'/itens', ['client_uuid' => (string) Str::uuid(), 'items' => [['product_id' => $product->id, 'quantity' => '1']]])->assertUnprocessable();
        $this->assertSame(0, ProductionWork::count());
        [$sale] = app(RegisterSale::class)->handle(['client_uuid' => (string) Str::uuid(), 'items' => [['product_id' => $product->id, 'quantity' => '1', 'expected_price' => '10.00']], 'payments' => [['method' => 'pix', 'amount' => '10.00']]]);
        $this->assertSame('10.00', $sale->total);
        $this->assertSame('9.000', $product->fresh()->stock_quantity);
    }
}
