<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\DiningTable;
use App\Models\MenuOrderReceipt;
use App\Models\Product;
use App\Models\ProductionSector;
use App\Models\ProductionWork;
use App\Models\RestaurantOperationSettings;
use App\Models\Sale;
use App\Models\StockMovement;
use App\Models\TableOrder;
use App\Models\TableOrderItem;
use App\Support\RestaurantOperationPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class ProductionAutomaticTest extends TestCase
{
    use RefreshDatabase;

    public function test_old_automatic_preference_stays_manual_and_activation_requires_risk_consent(): void
    {
        $admin = $this->adminOf();
        $this->actingAs($admin);
        RestaurantOperationSettings::factory()->create(['company_id' => $admin->company_id, 'confirmation_mode' => 'automatic']);
        $sector = ProductionSector::factory()->create(['company_id' => $admin->company_id]);
        $admin->company->forceFill(['default_production_sector_id' => $sector->id])->save();
        $this->assertSame('manual', app(RestaurantOperationPolicy::class)->resolve($admin->company)['effective_mode']);
        $this->putJson('/empresa/operacao', ['automatic_enabled' => true])->assertUnprocessable()->assertJsonValidationErrors('remote_risk_accepted');
        $this->assertNull(RestaurantOperationSettings::sole()->automatic_accepted_at);
    }

    public function test_explicit_activation_dispatches_new_receipts_but_does_not_confirm_existing_pending(): void
    {
        $admin = $this->adminOf();
        $sector = ProductionSector::factory()->create(['company_id' => $admin->company_id]);
        $admin->company->forceFill(['default_production_sector_id' => $sector->id, 'qr_menu_enabled' => true])->save();
        $product = Product::factory()->create(['company_id' => $admin->company_id]);
        $table = DiningTable::factory()->create(['company_id' => $admin->company_id]);
        $data = ['client_uuid' => (string) Str::uuid(), 'service_context' => $table->service_context, 'tracking_key' => str_repeat('a', 64), 'items' => [['product_id' => $product->id, 'quantity' => 1]]];
        $this->postJson('/m/'.$table->qr_token, $data)->assertCreated()->assertJsonPath('receipt.status', 'pending');
        $old = MenuOrderReceipt::sole();
        $this->actingAs($admin)->putJson('/empresa/operacao', ['confirmation_mode' => 'automatic', 'automatic_enabled' => true, 'remote_risk_accepted' => true])->assertOk();
        $this->assertSame($admin->id, RestaurantOperationSettings::sole()->automatic_accepted_by_id);
        $data['client_uuid'] = (string) Str::uuid();
        $this->postJson('/m/'.$table->qr_token, $data)->assertCreated()->assertJsonPath('receipt.status', 'confirmed');
        $this->assertSame('pending', $old->fresh()->status);
        $this->assertSame(1, ProductionWork::count());
        $this->assertSame(0, Sale::count());
        $this->assertSame(0, StockMovement::count());
        $settings = RestaurantOperationSettings::sole();
        $settings->forceFill(['automatic_blocked' => true])->save();
        $data['client_uuid'] = (string) Str::uuid();
        $this->postJson('/m/'.$table->qr_token, $data)->assertCreated()->assertJsonPath('receipt.status', 'pending');
        $this->assertSame(1, ProductionWork::count());
        $this->assertTrue($settings->fresh()->automatic_enabled);
    }

    public function test_activation_needs_destination_and_dispatch_failure_rolls_back_everything(): void
    {
        $admin = $this->adminOf();
        $this->actingAs($admin);
        $this->putJson('/empresa/operacao', ['confirmation_mode' => 'automatic', 'automatic_enabled' => true, 'remote_risk_accepted' => true])->assertUnprocessable()->assertJsonValidationErrors('automatic_enabled');
        $this->assertSame(0, RestaurantOperationSettings::count());
        $sector = ProductionSector::factory()->create(['company_id' => $admin->company_id]);
        $inactive = ProductionSector::factory()->create(['company_id' => $admin->company_id, 'active' => false]);
        $admin->company->forceFill(['default_production_sector_id' => $sector->id, 'qr_menu_enabled' => true])->save();
        $category = Category::factory()->create(['company_id' => $admin->company_id, 'production_sector_id' => $inactive->id]);
        $product = Product::factory()->create(['company_id' => $admin->company_id, 'category_id' => $category->id]);
        $table = DiningTable::factory()->create(['company_id' => $admin->company_id]);
        $this->putJson('/empresa/operacao', ['confirmation_mode' => 'automatic', 'automatic_enabled' => true, 'remote_risk_accepted' => true])->assertOk();
        $this->postJson('/m/'.$table->qr_token, ['client_uuid' => (string) Str::uuid(), 'tracking_key' => str_repeat('a', 64), 'service_context' => $table->service_context, 'items' => [['product_id' => $product->id, 'quantity' => 1]]])->assertUnprocessable()->assertJsonValidationErrors('production');
        $this->assertSame(0, MenuOrderReceipt::count());
        $this->assertSame(0, ProductionWork::count());
        $this->assertSame(0, TableOrder::count());
        $this->assertSame(0, TableOrderItem::count());
        $this->assertSame(0, Sale::count());
        $this->assertSame(0, StockMovement::count());
    }

    public function test_unprivileged_user_cannot_enable_automatic_and_manual_form_does_not_need_consent(): void
    {
        $admin = $this->adminOf();
        $plain = $this->userWith(['tables.operate'], $admin->company);
        $this->actingAs($plain)->putJson('/empresa/operacao', ['automatic_enabled' => true, 'remote_risk_accepted' => true])->assertForbidden();
        $this->assertSame(0, RestaurantOperationSettings::count());
        $this->actingAs($admin)->putJson('/empresa/operacao', ['automatic_enabled' => false, 'confirmation_mode' => 'manual', 'remote_risk_accepted' => false])->assertOk();
        $this->assertFalse(RestaurantOperationSettings::sole()->automatic_enabled);
        $this->assertNull(RestaurantOperationSettings::sole()->automatic_accepted_at);
    }
}
