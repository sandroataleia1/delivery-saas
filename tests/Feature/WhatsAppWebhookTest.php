<?php

namespace Tests\Feature;

use App\Jobs\ProcessWhatsAppInbound;
use App\Models\Company;
use App\Models\WhatsAppSetting;
use App\Support\EvolutionClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class WhatsAppWebhookTest extends TestCase
{
    use RefreshDatabase;

    private WhatsAppSetting $setting;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        config(['services.evolution.url' => 'https://evo.test', 'services.evolution.key' => 'KEY']);
        $this->setting = WhatsAppSetting::factory()->create(['instance_name' => 'inst-a', 'webhook_secret' => 'segredo-a']);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_replace_recursive([
            'event' => 'messages.upsert',
            'instance' => 'inst-a',
            'data' => [
                'key' => ['id' => 'ABC', 'remoteJid' => '5511988887777@s.whatsapp.net', 'fromMe' => false],
                'message' => ['conversation' => 'oi'],
            ],
        ], $overrides);
    }

    public function test_valid_call_stores_message_once_and_queues_processing(): void
    {
        Queue::fake();

        $this->postJson('/webhooks/whatsapp/inst-a/segredo-a', $this->payload())->assertOk();
        $this->postJson('/webhooks/whatsapp/inst-a/segredo-a', $this->payload())->assertOk();

        $this->assertSame(1, DB::table('whatsapp_inbound_messages')->count());
        $row = DB::table('whatsapp_inbound_messages')->first();
        $this->assertSame($this->setting->company_id, (int) $row->company_id);
        $this->assertSame('ABC', $row->wa_message_id);
        $this->assertSame('5511988887777@s.whatsapp.net', $row->remote_jid);
        Queue::assertPushed(ProcessWhatsAppInbound::class, 1);
    }

    public function test_rate_limit_is_counted_per_instance(): void
    {
        Queue::fake();
        WhatsAppSetting::factory()->create(['instance_name' => 'inst-b', 'webhook_secret' => 'segredo-b']);

        foreach (range(1, 240) as $attempt) {
            $this->postJson('/webhooks/whatsapp/inst-b/segredo-b', $this->payload(['instance' => 'inst-b', 'data' => ['key' => ['id' => "B{$attempt}"]]]));
        }

        $this->postJson('/webhooks/whatsapp/inst-b/segredo-b', $this->payload(['instance' => 'inst-b', 'data' => ['key' => ['id' => 'B-extra']]]))->assertStatus(429);
        $this->postJson('/webhooks/whatsapp/inst-a/segredo-a', $this->payload())->assertOk();
    }

    public function test_instance_api_key_sent_by_evolution_is_not_stored(): void
    {
        Queue::fake();
        $payload = json_decode(file_get_contents(base_path('tests/Fixtures/whatsapp/customer_text.json')), true);

        $this->postJson('/webhooks/whatsapp/inst-a/segredo-a', [...$payload, 'apikey' => 'TOKEN-DA-INSTANCIA', 'destination' => 'https://x/segredo-a'])->assertOk();

        $stored = DB::table('whatsapp_inbound_messages')->value('payload');
        $this->assertStringNotContainsString('TOKEN-DA-INSTANCIA', $stored);
        $this->assertStringNotContainsString('segredo-a', $stored);
        $this->assertSame('123456789012345@lid', DB::table('whatsapp_inbound_messages')->value('remote_jid'));
    }

    public function test_wrong_secret_or_unknown_instance_is_404(): void
    {
        Queue::fake();

        $this->postJson('/webhooks/whatsapp/inst-a/errado', $this->payload())->assertNotFound();
        $this->postJson('/webhooks/whatsapp/inst-x/segredo-a', $this->payload())->assertNotFound();

        $this->assertSame(0, DB::table('whatsapp_inbound_messages')->count());
        Queue::assertNothingPushed();
    }

    public function test_other_events_and_group_messages_are_accepted_but_ignored(): void
    {
        Queue::fake();

        $this->postJson('/webhooks/whatsapp/inst-a/segredo-a', $this->payload(['event' => 'connection.update']))->assertOk();
        $this->postJson('/webhooks/whatsapp/inst-a/segredo-a', $this->payload(['data' => ['key' => ['id' => 'G1', 'remoteJid' => '120363000000@g.us']]]))->assertOk();
        $this->postJson('/webhooks/whatsapp/inst-a/segredo-a', $this->payload(['data' => ['key' => ['id' => 'S1', 'remoteJid' => 'status@broadcast']]]))->assertOk();

        $this->assertSame(0, DB::table('whatsapp_inbound_messages')->count());
        Queue::assertNothingPushed();
    }

    public function test_malformed_payload_still_returns_200(): void
    {
        Queue::fake();

        $this->postJson('/webhooks/whatsapp/inst-a/segredo-a', [])->assertOk();
        $this->postJson('/webhooks/whatsapp/inst-a/segredo-a', ['event' => 'messages.upsert', 'data' => 'x'])->assertOk();

        $this->assertSame(0, DB::table('whatsapp_inbound_messages')->count());
    }

    public function test_connect_registers_webhook_with_secret_url(): void
    {
        Http::fake([
            'evo.test/instance/connect/*' => Http::response(['base64' => 'QR']),
            'evo.test/webhook/set/*' => Http::response(['webhook' => []]),
        ]);
        $admin = $this->adminOf();
        WhatsAppSetting::factory()->create(['company_id' => $admin->company_id, 'instance_name' => 'inst-b', 'instance_token' => 'T']);

        $this->actingAs($admin)->post('/loja-online/whatsapp/conectar')->assertRedirect();

        $secret = WhatsAppSetting::where('company_id', $admin->company_id)->value('webhook_secret');
        $this->assertNotEmpty($secret);
        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/webhook/set/inst-b')
            && $request['webhook']['enabled'] === true
            && $request['webhook']['events'] === ['MESSAGES_UPSERT']
            && $request['webhook']['url'] === route('whatsapp.webhook', ['inst-b', $secret]));
    }

    public function test_send_text_returns_message_id(): void
    {
        Http::fake(['evo.test/message/sendText/*' => Http::response(['key' => ['id' => 'OUT1']])]);

        $this->assertSame('OUT1', app(EvolutionClient::class)->sendText('inst-a', 'T', '5511988887777', 'oi'));
    }

    public function test_register_webhooks_command_covers_every_instance(): void
    {
        WhatsAppSetting::factory()->create(['company_id' => Company::factory(), 'instance_name' => 'inst-c']);
        Http::fake([
            'evo.test/webhook/set/inst-a' => Http::response('erro', 500),
            'evo.test/webhook/set/inst-c' => Http::response(['webhook' => []]),
        ]);

        $this->artisan('whatsapp:register-webhooks')->assertExitCode(0);

        Http::assertSentCount(2);
        $this->assertNotNull(WhatsAppSetting::withoutGlobalScopes()->where('instance_name', 'inst-c')->value('webhook_secret'));
    }
}
