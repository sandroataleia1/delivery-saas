<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\WhatsAppMessage;
use App\Models\WhatsAppSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class WhatsAppSettingsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        config(['services.evolution.url' => 'https://evo.test', 'services.evolution.key' => 'KEY']);
        Http::fake(['evo.test/webhook/set/*' => Http::response(['webhook' => []])]);
    }

    private function orderFor(int $companyId, int $number): int
    {
        return DB::table('online_orders')->insertGetId([
            'company_id' => $companyId, 'number' => $number, 'client_uuid' => "uuid-$companyId-$number",
            'content_hash' => str_repeat('a', 64), 'tracking_token_hash' => hash('sha256', "t-$companyId-$number"),
            'tracking_token' => 'x', 'status' => 'pending', 'type' => 'pickup', 'customer_name' => 'Joana',
            'customer_phone' => '11988887777', 'subtotal' => 10, 'total' => 10, 'payment_method' => 'pix',
            'expires_at' => now()->addHour(), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_admin_connects_and_sees_qr(): void
    {
        Http::fake([
            'evo.test/instance/create' => Http::response(['hash' => 'TOK', 'qrcode' => ['base64' => 'QR1']]),
            'evo.test/instance/connectionState/*' => Http::response(['instance' => ['state' => 'connecting']]),
        ]);
        $admin = $this->adminOf();

        $this->actingAs($admin)->post('/loja-online/whatsapp/conectar')->assertRedirect();

        $setting = WhatsAppSetting::where('company_id', $admin->company_id)->firstOrFail();
        $this->assertStringStartsWith('delivery-'.$admin->company_id.'-', $setting->instance_name);
        $this->assertSame('TOK', $setting->instance_token);
        $this->assertNotSame('TOK', DB::table('whatsapp_settings')->value('instance_token'));

        $this->get('/loja-online/whatsapp')->assertInertia(fn (Assert $page) => $page
            ->component('online/WhatsApp')
            ->where('available', true)
            ->where('qr', 'QR1')
            ->where('settings.connected', false)
            ->where('settings.has_instance', true)
            ->has('settings.templates', 9));
    }

    public function test_reconnect_uses_existing_instance(): void
    {
        Http::fake(['evo.test/instance/connect/*' => Http::response(['base64' => 'QR2'])]);
        $admin = $this->adminOf();
        WhatsAppSetting::factory()->create(['company_id' => $admin->company_id, 'instance_name' => 'inst-a', 'instance_token' => 'T', 'state' => 'close']);

        $this->actingAs($admin)->post('/loja-online/whatsapp/conectar')->assertRedirect();

        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/instance/connect/inst-a'));
        Http::assertNotSent(fn (Request $request) => str_contains($request->url(), 'instance/create'));
        $this->assertSame('QR2', Cache::get('whatsapp-qr:'.$admin->company_id));
    }

    public function test_reconnect_recreates_instance_missing_on_server(): void
    {
        Http::fake([
            'evo.test/instance/connect/*' => Http::response('not found', 404),
            'evo.test/instance/create' => Http::response(['hash' => 'TOK2', 'qrcode' => ['base64' => 'QR3']]),
        ]);
        $admin = $this->adminOf();
        WhatsAppSetting::factory()->create(['company_id' => $admin->company_id, 'instance_name' => 'inst-a', 'instance_token' => 'T', 'state' => 'close']);

        $this->actingAs($admin)->post('/loja-online/whatsapp/conectar')->assertRedirect()->assertSessionHasNoErrors();

        Http::assertSent(fn (Request $request) => str_contains($request->url(), 'instance/create'));
        $setting = WhatsAppSetting::where('company_id', $admin->company_id)->firstOrFail();
        $this->assertNotSame('inst-a', $setting->instance_name);
        $this->assertStringStartsWith('delivery-'.$admin->company_id.'-', $setting->instance_name);
        $this->assertSame('TOK2', $setting->instance_token);
        $this->assertSame('QR3', Cache::get('whatsapp-qr:'.$admin->company_id));
    }

    public function test_disconnect_clears_instance_missing_on_server(): void
    {
        Http::fake(['evo.test/instance/logout/*' => Http::response('not found', 404)]);
        $admin = $this->adminOf();
        WhatsAppSetting::factory()->create(['company_id' => $admin->company_id, 'instance_name' => 'inst-a', 'instance_token' => 'T', 'state' => 'open']);
        Cache::put('whatsapp-qr:'.$admin->company_id, 'QR', 60);

        $this->actingAs($admin)->delete('/loja-online/whatsapp/conexao')->assertRedirect()->assertSessionHasNoErrors();

        $setting = WhatsAppSetting::where('company_id', $admin->company_id)->firstOrFail();
        $this->assertNull($setting->instance_name);
        $this->assertNull($setting->instance_token);
        $this->assertSame('close', $setting->state);
        $this->assertNull(Cache::get('whatsapp-qr:'.$admin->company_id));
    }

    public function test_evolution_error_is_friendly(): void
    {
        Http::fake(['evo.test/instance/create' => Http::response('boom', 500)]);

        $this->actingAs($this->adminOf())->postJson('/loja-online/whatsapp/conectar')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['whatsapp' => 'Não foi possível falar com o servidor do WhatsApp. Tente de novo em instantes.']);
    }

    public function test_not_configured_server(): void
    {
        config(['services.evolution.url' => '']);
        $admin = $this->adminOf();

        $this->actingAs($admin)->get('/loja-online/whatsapp')->assertInertia(fn (Assert $page) => $page
            ->where('available', false)
            ->where('settings.has_instance', false)
            ->where('settings.enabled', false)
            ->where('settings.state', null));

        $this->postJson('/loja-online/whatsapp/conectar')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['whatsapp' => 'O WhatsApp ainda não foi configurado no servidor.']);
    }

    public function test_saves_enabled_and_only_non_empty_overrides(): void
    {
        $admin = $this->adminOf();

        $this->actingAs($admin)->putJson('/loja-online/whatsapp', [
            'enabled' => true,
            'templates' => ['accepted' => ' Oi {nome} ', 'preparing' => '  '],
        ])->assertRedirect();

        $setting = WhatsAppSetting::where('company_id', $admin->company_id)->firstOrFail();
        $this->assertTrue($setting->enabled);
        $this->assertSame(['accepted' => 'Oi {nome}'], $setting->templates);

        $this->putJson('/loja-online/whatsapp', ['enabled' => true, 'templates' => ['foo' => 'x']])
            ->assertStatus(422)
            ->assertJsonValidationErrors('templates');
    }

    public function test_history_is_scoped_to_company(): void
    {
        $admin = $this->adminOf();
        $other = Company::factory()->create();
        WhatsAppMessage::factory()->create(['online_order_id' => $this->orderFor($admin->company_id, 7), 'event' => 'accepted']);
        WhatsAppMessage::factory()->create(['online_order_id' => $this->orderFor($other->id, 99)]);

        $this->actingAs($admin)->get('/loja-online/whatsapp')->assertInertia(fn (Assert $page) => $page
            ->has('messages', 1)
            ->where('messages.0.number', 7)
            ->where('messages.0.event_label', 'Pedido aceito'));
    }

    public function test_requires_company_manage(): void
    {
        $waiter = $this->userWith(['online_orders.accept']);

        $this->actingAs($waiter);
        $this->get('/loja-online/whatsapp')->assertForbidden();
        $this->post('/loja-online/whatsapp/conectar')->assertForbidden();
        $this->delete('/loja-online/whatsapp/conexao')->assertForbidden();
        $this->put('/loja-online/whatsapp', ['enabled' => true])->assertForbidden();
    }

    public function test_disconnect_logs_out(): void
    {
        Http::fake(['evo.test/instance/logout/*' => Http::response(['status' => 'SUCCESS'])]);
        $admin = $this->adminOf();
        $setting = WhatsAppSetting::factory()->create(['company_id' => $admin->company_id, 'instance_name' => 'inst-a', 'state' => 'open']);
        Cache::put('whatsapp-qr:'.$admin->company_id, 'QR', 60);

        $this->actingAs($admin)->delete('/loja-online/whatsapp/conexao')->assertRedirect();

        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/instance/logout/inst-a'));
        $this->assertSame('close', $setting->fresh()->state);
        $this->assertNull(Cache::get('whatsapp-qr:'.$admin->company_id));
    }
}
