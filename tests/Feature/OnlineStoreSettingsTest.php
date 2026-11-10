<?php

namespace Tests\Feature;

use App\Models\OnlineStore;
use App\Models\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class OnlineStoreSettingsTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, mixed> */
    private function payload(array $override = []): array
    {
        return [
            'enabled' => true,
            'slug' => 'pizzaria-exemplo',
            'accepts_delivery' => true,
            'accepts_pickup' => true,
            'min_order' => '30,00',
            'eta_text' => '40 a 60 min',
            'whatsapp' => '(11) 99999-1234',
            'accept_minutes' => 15,
            'hours' => [['weekday' => 6, 'opens_at' => '18:00', 'closes_at' => '02:00']],
            ...$override,
        ];
    }

    public function test_admin_saves_store_with_overnight_shift(): void
    {
        $admin = $this->adminOf();

        $this->actingAs($admin)->putJson('/loja-online/configuracao', $this->payload())->assertRedirect();

        $store = OnlineStore::where('company_id', $admin->company_id)->firstOrFail();
        $this->assertSame('pizzaria-exemplo', $store->slug);
        $this->assertSame('30.00', $store->min_order);
        $this->assertSame('11999991234', $store->whatsapp);
        $this->assertSame(15, $store->accept_minutes);

        $this->get('/loja-online/configuracao')->assertInertia(fn (Assert $page) => $page
            ->component('online/Settings')
            ->where('store.slug', 'pizzaria-exemplo')
            ->where('store.accept_minutes', 15)
            ->where('store.hours.0.weekday', 6)
            ->where('store.hours.0.opens_at', '18:00')
            ->where('store.hours.0.closes_at', '02:00'));

        $this->putJson('/loja-online/configuracao', $this->payload(['hours' => []]))->assertRedirect();
        $this->assertDatabaseCount('online_store_hours', 0);
    }

    public function test_store_whatsapp_is_saved_without_country_code(): void
    {
        $admin = $this->adminOf();

        $this->actingAs($admin)->putJson('/loja-online/configuracao', $this->payload(['whatsapp' => '+55 (11) 99999-1234']))->assertRedirect();
        $this->assertSame('11999991234', OnlineStore::where('company_id', $admin->company_id)->value('whatsapp'));

        $this->putJson('/loja-online/configuracao', $this->payload(['whatsapp' => '999991234']))->assertJsonValidationErrors('whatsapp');
    }

    public function test_slug_must_be_unique_and_well_formed(): void
    {
        OnlineStore::factory()->create(['slug' => 'ocupado']);
        $admin = $this->adminOf();

        $this->actingAs($admin)->putJson('/loja-online/configuracao', $this->payload(['slug' => 'ocupado']))
            ->assertUnprocessable()->assertJsonValidationErrors('slug');
        $this->putJson('/loja-online/configuracao', $this->payload(['slug' => 'Pizza Exemplo']))
            ->assertUnprocessable()->assertJsonValidationErrors('slug');

        $own = OnlineStore::factory()->create(['company_id' => $admin->company_id, 'slug' => 'minha-loja']);
        $this->putJson('/loja-online/configuracao', $this->payload(['slug' => 'minha-loja']))->assertRedirect();
        $this->assertSame(1, OnlineStore::where('id', $own->id)->count());
    }

    public function test_overlapping_shifts_rejected(): void
    {
        $admin = $this->adminOf();

        $this->actingAs($admin)->putJson('/loja-online/configuracao', $this->payload(['hours' => [
            ['weekday' => 5, 'opens_at' => '18:00', 'closes_at' => '23:00'],
            ['weekday' => 5, 'opens_at' => '22:00', 'closes_at' => '23:30'],
        ]]))->assertUnprocessable()->assertJsonValidationErrors('hours');

        $this->putJson('/loja-online/configuracao', $this->payload(['hours' => [
            ['weekday' => 6, 'opens_at' => '18:00', 'closes_at' => '02:00'],
            ['weekday' => 0, 'opens_at' => '01:00', 'closes_at' => '05:00'],
        ]]))->assertUnprocessable()->assertJsonValidationErrors('hours');

        $this->assertDatabaseCount('online_stores', 0);
    }

    public function test_enabled_store_requires_a_service_type(): void
    {
        $this->actingAs($this->adminOf())->putJson('/loja-online/configuracao', $this->payload([
            'accepts_delivery' => false,
            'accepts_pickup' => false,
        ]))->assertUnprocessable()->assertJsonValidationErrors('accepts_delivery');
    }

    public function test_settings_require_company_manage(): void
    {
        $caixa = $this->userWith(['cash.operate']);

        $this->actingAs($caixa)->get('/loja-online/configuracao')->assertForbidden();
        $this->putJson('/loja-online/configuracao', $this->payload())->assertForbidden();
    }

    public function test_grant_migration_gives_accept_permission_to_roles_with_tables_confirm(): void
    {
        $migration = require database_path('migrations/2026_11_10_000002_grant_online_order_permissions.php');
        $waiter = Role::factory()->create(['name' => 'Garçom', 'permissions' => ['tables.confirm']]);
        $other = Role::factory()->create(['name' => 'Entregador', 'permissions' => ['deliveries.courier']]);

        $migration->up();

        $this->assertSame(['tables.confirm', 'online_orders.accept'], $waiter->fresh()->permissions);
        $this->assertSame(['deliveries.courier'], $other->fresh()->permissions);
    }
}
