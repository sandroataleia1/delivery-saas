<?php

namespace Tests\Feature;

use App\Models\RestaurantOperationSettings;
use App\Models\User;
use App\Support\RestaurantOperationPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RestaurantOperationSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_defaults_and_automatic_preference_require_functional_production(): void
    {
        $admin = $this->adminOf();
        $this->actingAs($admin);
        $policy = app(RestaurantOperationPolicy::class);
        $this->assertSame('manual', $policy->resolve($admin->company)['effective_mode']);
        $this->putJson('/empresa/operacao', ['confirmation_mode' => 'automatic', 'max_order_value' => '300'])->assertSuccessful();
        $settings = $policy->resolve($admin->company);
        $this->assertSame('automatic', $settings['confirmation_mode']);
        $this->assertSame('manual', $settings['effective_mode']);
        $this->assertSame('production_unavailable', $settings['effective_reason']);
        $this->assertSame('300.00', $settings['max_order_value']);
    }

    public function test_restaurant_cannot_edit_block_or_company_and_limits_are_enforced(): void
    {
        $admin = $this->adminOf();
        $foreign = $this->adminOf();
        $this->actingAs($admin)->putJson('/empresa/operacao', ['automatic_blocked' => false, 'company_id' => $foreign->company_id])->assertUnprocessable();
        $this->putJson('/empresa/operacao', ['max_lines' => 31, 'max_order_value' => '5000.01'])->assertUnprocessable();
        $this->assertDatabaseCount('restaurant_operation_settings', 0);
        $this->putJson('/empresa/operacao', ['max_lines' => 10])->assertSuccessful();
        $this->assertDatabaseHas('restaurant_operation_settings', ['company_id' => $admin->company_id, 'max_lines' => 10]);
        $this->assertDatabaseMissing('restaurant_operation_settings', ['company_id' => $foreign->company_id]);
    }

    public function test_only_super_admin_blocks_with_reason_preserving_preference(): void
    {
        $admin = $this->adminOf();
        $this->actingAs($admin)->putJson('/empresa/operacao', ['confirmation_mode' => 'automatic'])->assertSuccessful();
        $url = '/admin/empresas/'.$admin->company_id.'/operacao/bloqueio';
        $this->postJson($url, ['blocked' => true, 'reason' => 'Abuso'])->assertForbidden();
        $super = User::factory()->superAdmin()->create();
        $this->actingAs($super)->postJson($url, ['blocked' => true])->assertUnprocessable();
        $this->postJson($url, ['blocked' => true, 'reason' => 'Abuso confirmado'])->assertSuccessful();
        $row = RestaurantOperationSettings::withoutGlobalScopes()->sole();
        $this->assertTrue($row->automatic_blocked);
        $this->assertSame($super->id, $row->automatic_blocked_by_id);
        $this->assertNotNull($row->automatic_blocked_at);
        $this->assertSame('automatic', $row->confirmation_mode);
        $this->assertSame('platform_block', app(RestaurantOperationPolicy::class)->resolve($admin->company)['effective_reason']);
        $this->postJson($url, ['blocked' => false, 'reason' => 'Revisado'])->assertSuccessful();
        $this->assertFalse($row->fresh()->automatic_blocked);
        $this->assertDatabaseHas('audit_logs', ['auditable_type' => 'RestaurantOperationSettings', 'user_id' => $super->id]);
    }
}
