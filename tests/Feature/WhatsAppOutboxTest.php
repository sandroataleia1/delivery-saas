<?php

namespace Tests\Feature;

use App\Actions\AcceptOnlineOrder;
use App\Actions\AdvanceDeliveryOrder;
use App\Actions\CancelDeliveryOrder;
use App\Actions\ExpireOnlineOrders;
use App\Actions\PlaceOnlineOrder;
use App\Actions\RejectOnlineOrder;
use App\Actions\TransitionProduction;
use App\Jobs\SendWhatsAppMessage;
use App\Models\Company;
use App\Models\DeliveryOrder;
use App\Models\DeliveryZone;
use App\Models\OnlineOrder;
use App\Models\OnlineStore;
use App\Models\OnlineStoreHour;
use App\Models\Product;
use App\Models\ProductionWork;
use App\Models\User;
use App\Models\WhatsAppMessage;
use App\Models\WhatsAppSetting;
use App\Support\WhatsAppTemplates;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

class WhatsAppOutboxTest extends TestCase
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

        Queue::fake();
        Http::preventStrayRequests();

        $this->admin = $this->productionAdminOf();
        $companyId = $this->admin->company_id;
        $this->store = OnlineStore::factory()->create(['company_id' => $companyId, 'enabled' => true, 'slug' => 'lanchonete', 'accept_minutes' => 15]);
        (new OnlineStoreHour)->forceFill(['company_id' => $companyId, 'weekday' => 6, 'opens_at' => '18:00:00', 'closes_at' => '23:00:00'])->save();
        $this->burger = Product::factory()->create(['company_id' => $companyId, 'name' => 'X-Bacon', 'sale_price' => '28.00', 'track_stock' => false]);
        $this->zone = new DeliveryZone(['name' => 'Centro', 'fee' => '6.00']);
        $this->zone->company_id = $companyId;
        $this->zone->save();
        $this->settings = WhatsAppSetting::factory()->create(['company_id' => $companyId, 'enabled' => true, 'instance_name' => 'inst-a']);

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

    /** @return array<string, mixed> */
    private function payload(string $type = 'delivery'): array
    {
        $payload = [
            'client_uuid' => (string) Str::uuid(),
            'type' => $type,
            'customer' => ['name' => 'Joana', 'phone' => '(11) 98888-7777'],
            'items' => [['product_id' => $this->burger->id, 'quantity' => 2]],
            'payment_method' => 'pix',
        ];
        if ($type === 'delivery') {
            $payload['address'] = ['street' => 'Rua das Flores', 'number' => '45', 'delivery_zone_id' => $this->zone->id];
        }

        return $payload;
    }

    private function place(string $type = 'delivery'): OnlineOrder
    {
        return Context::scope(
            fn () => app(PlaceOnlineOrder::class)->handle($this->store, $this->payload($type)),
            hidden: ['audit_actor' => PlaceOnlineOrder::GUEST],
        )['order'];
    }

    private function accept(OnlineOrder $order): DeliveryOrder
    {
        $this->actingAs($this->admin);

        return app(AcceptOnlineOrder::class)->handle($order);
    }

    private function advanceWork(DeliveryOrder $delivery, string $target): void
    {
        $this->actingAs($this->admin);
        $work = ProductionWork::whereIn('delivery_order_item_id', $delivery->items()->pluck('id'))->firstOrFail();
        app(TransitionProduction::class)->handle($work, $work->version, $target);
    }

    private function message(OnlineOrder $order, string $event): WhatsAppMessage
    {
        return WhatsAppMessage::withoutGlobalScopes()->where('online_order_id', $order->id)->where('event', $event)->sole();
    }

    /** @return list<string> */
    private function events(OnlineOrder $order): array
    {
        return WhatsAppMessage::withoutGlobalScopes()->where('online_order_id', $order->id)->orderBy('id')->pluck('event')->all();
    }

    public function test_placing_order_records_received_with_link_and_total(): void
    {
        $order = $this->place();

        $message = $this->message($order, 'received');
        $this->assertSame('5511988887777', $message->phone);
        $this->assertSame(WhatsAppMessage::PENDING, $message->status);
        $this->assertSame($order->company_id, $message->company_id);
        $this->assertStringContainsString('#'.$order->number, $message->body);
        $this->assertStringContainsString(route('shop.track', ['lanchonete', $order->tracking_token]), $message->body);
        $this->assertStringContainsString('R$', $message->body);
        $this->assertStringContainsString('62,00', $message->body);
        Queue::assertPushed(SendWhatsAppMessage::class, 1);
        Queue::assertPushed(SendWhatsAppMessage::class, fn (SendWhatsAppMessage $job) => $job->messageId === $message->id);
    }

    public function test_each_stage_records_one_message(): void
    {
        $pickup = $this->place('pickup');
        $delivery = $this->accept($pickup);
        $this->advanceWork($delivery, 'preparing');
        $this->advanceWork($delivery, 'ready');
        app(AdvanceDeliveryOrder::class)->handle($delivery->fresh(), DeliveryOrder::READY);

        $this->assertSame(['received', 'accepted', 'preparing', 'ready_for_pickup', 'delivered'], $this->events($pickup));

        $online = $this->place();
        $delivery = $this->accept($online);
        $this->advanceWork($delivery, 'preparing');
        $this->advanceWork($delivery, 'ready');
        $courier = User::factory()->create(['company_id' => $this->admin->company_id]);
        app(AdvanceDeliveryOrder::class)->handle($delivery->fresh(), DeliveryOrder::READY, $courier);

        $this->assertSame(['received', 'accepted', 'preparing', 'out_for_delivery'], $this->events($online));
        Queue::assertPushed(SendWhatsAppMessage::class, 9);
    }

    public function test_rejected_expired_and_cancelled_carry_reason(): void
    {
        $rejected = $this->place();
        $this->actingAs($this->admin);
        app(RejectOnlineOrder::class)->handle($rejected, 'Sem entregador');
        $this->assertStringContainsString('Motivo: Sem entregador', $this->message($rejected, 'rejected')->body);

        $expired = $this->place();
        app(ExpireOnlineOrders::class)->handle(CarbonImmutable::now()->addMinutes(20));
        $this->assertSame(OnlineOrder::EXPIRED, $expired->fresh()->status);
        $this->assertStringContainsString(route('shop.track', ['lanchonete', $expired->tracking_token]), $this->message($expired, 'expired')->body);

        $cancelled = $this->place();
        $delivery = $this->accept($cancelled);
        app(CancelDeliveryOrder::class)->handle($delivery->fresh(), 'Cliente desistiu');
        $this->assertStringContainsString('Cliente desistiu', $this->message($cancelled, 'cancelled')->body);
    }

    public function test_same_event_twice_records_once(): void
    {
        $online = $this->place();
        $delivery = $this->accept($online);

        $delivery->forceFill(['status' => DeliveryOrder::PREPARING])->save();
        $delivery->forceFill(['status' => DeliveryOrder::RECEIVED])->save();
        $delivery->forceFill(['status' => DeliveryOrder::PREPARING])->save();

        $preparing = $this->message($online, 'preparing');
        Queue::assertPushed(SendWhatsAppMessage::class, fn (SendWhatsAppMessage $job) => $job->messageId === $preparing->id);
        $this->assertCount(1, Queue::pushed(SendWhatsAppMessage::class, fn (SendWhatsAppMessage $job) => $job->messageId === $preparing->id));
        Queue::assertPushed(SendWhatsAppMessage::class, 3);
    }

    public function test_nothing_recorded_without_enabled_settings(): void
    {
        $this->settings->forceFill(['enabled' => false])->save();
        $this->accept($this->place());

        $this->settings->forceFill(['enabled' => true, 'instance_name' => null])->save();
        $this->accept($this->place());

        $this->settings->delete();
        $this->accept($this->place());

        $this->assertSame(3, OnlineOrder::withoutGlobalScopes()->where('status', OnlineOrder::ACCEPTED)->count());
        $this->assertSame(0, WhatsAppMessage::withoutGlobalScopes()->count());
        Queue::assertNothingPushed();
    }

    public function test_other_company_settings_never_used(): void
    {
        $other = Company::factory()->create();
        $otherStore = OnlineStore::factory()->create(['company_id' => $other->id, 'enabled' => true, 'slug' => 'outra', 'accept_minutes' => 15]);
        (new OnlineStoreHour)->forceFill(['company_id' => $other->id, 'weekday' => 6, 'opens_at' => '18:00:00', 'closes_at' => '23:00:00'])->save();
        $product = Product::factory()->create(['company_id' => $other->id, 'sale_price' => '20.00', 'track_stock' => false]);

        $payload = $this->payload('pickup');
        $payload['items'] = [['product_id' => $product->id, 'quantity' => 1]];
        $this->postJson("/loja/{$otherStore->slug}/pedidos", $payload)->assertCreated();

        $order = OnlineOrder::withoutGlobalScopes()->where('company_id', $other->id)->sole();
        $this->assertSame(0, WhatsAppMessage::withoutGlobalScopes()->where('online_order_id', $order->id)->count());
        $this->assertSame(0, WhatsAppMessage::withoutGlobalScopes()->count());
        Queue::assertNothingPushed();
    }

    public function test_order_flow_survives_outbox_failure(): void
    {
        WhatsAppSetting::retrieved(fn () => throw new RuntimeException('boom'));

        $this->postJson('/loja/lanchonete/pedidos', $this->payload())->assertCreated()->assertJsonPath('number', 1);

        $this->assertSame(1, OnlineOrder::withoutGlobalScopes()->where('company_id', $this->admin->company_id)->count());
        $this->assertSame(0, WhatsAppMessage::withoutGlobalScopes()->count());
        Queue::assertNothingPushed();
    }

    public function test_render_appends_link_keeps_unknown_markers_and_falls_back_to_default(): void
    {
        $values = ['nome' => 'Joana', 'loja' => 'Lanchonete', 'numero' => '7', 'link' => 'https://l', 'motivo' => '', 'total' => 'R$ 10,00'];

        $custom = WhatsAppTemplates::render(['accepted' => 'Oi {nome}, pedido #{numero} {foo}'], 'accepted', $values);
        $this->assertSame("Oi Joana, pedido #7 {foo}\nhttps://l", $custom);

        $fallback = WhatsAppTemplates::render(['accepted' => '   '], 'accepted', $values);
        $this->assertSame("Joana, a Lanchonete aceitou seu pedido #7.\nAcompanhe: https://l", $fallback);
        $this->assertSame($fallback, WhatsAppTemplates::render(null, 'accepted', $values));
    }
}
