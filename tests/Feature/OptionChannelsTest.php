<?php

namespace Tests\Feature;

use App\Actions\DispatchProduction;
use App\Actions\OpenTable;
use App\Models\DiningTable;
use App\Models\Product;
use App\Models\ProductionSector;
use App\Models\ProductionWork;
use App\Models\TableOrderItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class OptionChannelsTest extends TestCase
{
    use RefreshDatabase;

    public function test_dispatch_copies_options_label_to_production_work(): void
    {
        $admin = $this->adminOf();
        $this->actingAs($admin);
        $sector = ProductionSector::factory()->create(['company_id' => $admin->company_id]);
        $admin->company->forceFill(['default_production_sector_id' => $sector->id])->save();
        $product = Product::factory()->create(['company_id' => $admin->company_id]);
        $order = app(OpenTable::class)->handle(DiningTable::factory()->create(['company_id' => $admin->company_id]), 1, $admin);
        $snapshot = ['size' => ['id' => 1, 'name' => 'Grande'], 'flavors' => []];
        $item = new TableOrderItem;
        $item->forceFill(['company_id' => $admin->company_id, 'table_order_id' => $order->id, 'product_id' => $product->id,
            'client_uuid' => (string) Str::uuid(), 'user_name' => $admin->name, 'product_name' => $product->name, 'unit' => $product->unit, 'quantity' => '1.000', 'unit_price' => '10.00', 'total' => '10.00',
            'options' => $snapshot, 'options_label' => 'Grande · ½ A ½ B'])->save();

        $work = app(DispatchProduction::class)->handle($item);

        $this->assertSame('Grande · ½ A ½ B', $work->fresh()->options_label);
        $this->assertSame($snapshot, ProductionWork::sole()->options);
        $this->actingAs($admin)->get(route('production.index'))
            ->assertInertia(fn ($page) => $page->where('works.0.options_label', 'Grande · ½ A ½ B'));
    }
}
