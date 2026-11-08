<?php

namespace Tests\Feature;

use App\Actions\PlaceOnlineOrder;
use App\Jobs\SendWhatsAppMessage;
use App\Models\Company;
use App\Models\DeliveryOrder;
use App\Models\DeliveryZone;
use App\Models\OnlineOrder;
use App\Models\OnlineStore;
use App\Models\OnlineStoreHour;
use App\Models\Product;
use App\Models\User;
use App\Models\WhatsAppMessage;
use App\Models\WhatsAppSetting;
use App\Support\EvolutionClient;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

class SendWhatsAppMessageTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private OnlineStore $store;

    private Product $burger;

    private DeliveryZone $zone;

    private WhatsAppSetting $settings;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        config(['services.evolution.url' => 'https://evo.test', 'services.evolution.key' => 'GLOBAL']);

        $this->admin = $this->productionAdminOf();
        $companyId = $this->admin->company_id;
        $this->store = OnlineStore::factory()->create(['company_id' => $companyId, 'enabled' => true, 'slug' => 'lanchonete', 'accept_minutes' => 15]);
        (new OnlineStoreHour)->forceFill(['company_id' => $companyId, 'weekday' => 6, 'opens_at' => '18:00:00', 'closes_at' => '23:00:00'])->save();
        $this->burger = Product::factory()->create(['company_id' => $companyId, 'name' => 'X-Bacon', 'sale_price' => '28.00', 'track_stock' => false]);
        $this->zone = new DeliveryZone(['name' => 'Centro', 'fee' => '6.00']);
        $this->zone->company_id = $companyId;
        $this->zone->save();
        $this->settings = WhatsAppSetting::factory()->create([
            'company_id' => $companyId, 'enabled' => true, 'instance_name' => 'inst-a', 'instance_token' => 'TOKEN-A', 'state' => 'unknown',
        ]);

        $moment = CarbonImmutable::parse('2026-11-07 19:00', 'America/Sao_Paulo');
        Carbon::setTestNow($moment);
        CarbonImmutable::setTestNow($moment);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    private function place(): OnlineOrder
    {
        return app(PlaceOnlineOrder::class)->handle($this->store, [
            'client_uuid' => (string) Str::uuid(),
            'type' => 'pickup',
            'customer' => ['name' => 'Joana', 'phone' => '(11) 98888-7777'],
            'items' => [['product_id' => $this->burger->id, 'quantity' => 1]],
            'payment_method' => 'pix',
        ])['order'];
    }

    /** @param array<string, mixed> $attributes */
    private function message(array $attributes = []): WhatsAppMessage
    {
        if (! isset($attributes['online_order_id'])) {
            Queue::fake();
        }
        $orderId = $attributes['online_order_id'] ?? $this->place()->id;
        unset($attributes['online_order_id']);
        $attributes += ['event' => 'manual'];

        $updatedAt = $attributes['updated_at'] ?? null;
        unset($attributes['updated_at']);

        $message = WhatsAppMessage::factory()->create(['online_order_id' => $orderId] + $attributes);
        if ($updatedAt !== null) {
            WhatsAppMessage::withoutGlobalScopes()->whereKey($message->id)->toBase()->update(['updated_at' => $updatedAt]);
        }

        return $message;
    }

    private function runJob(WhatsAppMessage $message): SendWhatsAppMessage
    {
        $job = (new SendWhatsAppMessage($message->id))->withFakeQueueInteractions();
        $job->handle(app(EvolutionClient::class));

        return $job;
    }

    private function fresh(WhatsAppMessage $message): WhatsAppMessage
    {
        return WhatsAppMessage::withoutGlobalScopes()->findOrFail($message->id);
    }

    private function sendTextCount(): int
    {
        return count(Http::recorded(fn (Request $request) => str_contains($request->url(), 'sendText')));
    }

    public function test_sends_once_and_marks_sent(): void
    {
        Http::fake(['*/message/sendText/inst-a' => Http::response(['key' => []], 201)]);
        $message = $this->message();

        $this->runJob($message);

        $row = $this->fresh($message);
        $this->assertSame(WhatsAppMessage::SENT, $row->status);
        $this->assertSame(1, $row->attempts);
        $this->assertNotNull($row->sent_at);
        $this->assertNull($row->last_error);
        $this->assertSame('open', WhatsAppSetting::withoutGlobalScopes()->find($this->settings->id)->state);
        Http::assertSentCount(1);

        $this->runJob($message);
        Http::assertSentCount(1);
    }

    public function test_failure_releases_with_backoff_then_fails_after_max_attempts(): void
    {
        Http::fake([
            '*/message/sendText/inst-a' => Http::response(['error' => 'x'], 500),
            '*/instance/connectionState/inst-a' => Http::response(['instance' => ['state' => 'close']]),
        ]);
        $message = $this->message();

        $this->runJob($message)->assertReleased(30);

        $row = $this->fresh($message);
        $this->assertSame(WhatsAppMessage::PENDING, $row->status);
        $this->assertSame(1, $row->attempts);
        $this->assertNotEmpty($row->last_error);
        $this->assertSame('close', WhatsAppSetting::withoutGlobalScopes()->find($this->settings->id)->state);

        foreach ([2, 3, 4, 5] as $attempt) {
            $this->runJob($message);
            $this->assertSame($attempt, $this->fresh($message)->attempts);
        }
        $this->assertSame(WhatsAppMessage::FAILED, $this->fresh($message)->status);

        $sent = $this->sendTextCount();
        $this->assertSame(5, $sent);
        $this->runJob($message);
        $this->assertSame($sent, $this->sendTextCount());
    }

    public function test_connection_timeout_never_throws(): void
    {
        Http::fake(fn () => throw new ConnectionException('timeout'));
        $message = $this->message();

        $this->runJob($message);

        $row = $this->fresh($message);
        $this->assertSame(WhatsAppMessage::PENDING, $row->status);
        $this->assertSame(1, $row->attempts);
    }

    public function test_disabled_or_missing_instance_fails_without_calling_evolution(): void
    {
        Http::fake();
        $this->settings->forceFill(['enabled' => false])->save();
        $message = $this->message();

        $this->runJob($message);

        $row = $this->fresh($message);
        $this->assertSame(WhatsAppMessage::FAILED, $row->status);
        $this->assertSame('WhatsApp desligado', $row->last_error);
        Http::assertNothingSent();
    }

    public function test_uses_the_message_company_instance(): void
    {
        Http::fake(['*/message/sendText/*' => Http::response([], 201)]);
        $other = Company::factory()->create();
        WhatsAppSetting::factory()->create([
            'company_id' => $other->id, 'enabled' => true, 'instance_name' => 'inst-b', 'instance_token' => 'TOKEN-B',
        ]);
        $message = $this->message();
        WhatsAppMessage::withoutGlobalScopes()->whereKey($message->id)->toBase()->update(['company_id' => $other->id]);

        Auth::guard()->logout();
        $this->runJob($message);

        $this->assertSame(WhatsAppMessage::SENT, $this->fresh($message)->status);
        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/message/sendText/inst-b')
            && $request->header('apikey') === ['TOKEN-B']);
    }

    public function test_accept_survives_evolution_outage(): void
    {
        Http::fake(['*' => Http::response(['error' => 'down'], 500)]);
        $order = $this->place();

        $this->actingAs($this->admin)->post(route('online.orders.accept', $order->id))->assertSessionHasNoErrors()->assertRedirect();

        $this->assertSame(1, DeliveryOrder::where('online_order_id', $order->id)->count());
        $accepted = WhatsAppMessage::withoutGlobalScopes()->where('online_order_id', $order->id)->where('event', 'accepted')->sole();
        $this->assertContains($accepted->status, [WhatsAppMessage::PENDING, WhatsAppMessage::FAILED]);
        $this->assertNotSame(WhatsAppMessage::SENT, $accepted->status);
    }

    public function test_sweep_redispatches_stale_pending_and_fails_stuck_sending(): void
    {
        Queue::fake();
        $orderId = $this->place()->id;
        $stale = $this->message(['online_order_id' => $orderId, 'event' => 'a', 'updated_at' => now()->subMinutes(40)]);
        $fresh = $this->message(['online_order_id' => $orderId, 'event' => 'b', 'updated_at' => now()->subMinutes(10)]);
        $stuck = $this->message(['online_order_id' => $orderId, 'event' => 'c', 'status' => WhatsAppMessage::SENDING, 'attempts' => 1, 'updated_at' => now()->subMinutes(20)]);
        $running = $this->message(['online_order_id' => $orderId, 'event' => 'd', 'status' => WhatsAppMessage::SENDING, 'attempts' => 1, 'updated_at' => now()->subMinutes(5)]);
        Queue::fake();

        Artisan::call('whatsapp:sweep');

        Queue::assertPushed(SendWhatsAppMessage::class, 1);
        Queue::assertPushed(SendWhatsAppMessage::class, fn (SendWhatsAppMessage $job) => $job->messageId === $stale->id);
        $this->assertSame(WhatsAppMessage::PENDING, $this->fresh($fresh)->status);
        $this->assertSame(WhatsAppMessage::FAILED, $this->fresh($stuck)->status);
        $this->assertSame('Envio interrompido; não reenviado para evitar mensagem duplicada.', $this->fresh($stuck)->last_error);
        $this->assertSame(WhatsAppMessage::SENDING, $this->fresh($running)->status);
    }
}
