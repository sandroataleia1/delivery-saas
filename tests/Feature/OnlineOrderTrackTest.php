<?php

namespace Tests\Feature;

use App\Actions\AcceptOnlineOrder;
use App\Actions\AdvanceDeliveryOrder;
use App\Actions\PlaceOnlineOrder;
use App\Actions\RejectOnlineOrder;
use App\Actions\TransitionProduction;
use App\Models\Company;
use App\Models\DeliveryOrder;
use App\Models\DeliveryZone;
use App\Models\OnlineOrder;
use App\Models\OnlineStore;
use App\Models\OnlineStoreHour;
use App\Models\Product;
use App\Models\ProductionWork;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class OnlineOrderTrackTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private OnlineStore $store;

    private Product $burger;

    private DeliveryZone $zone;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->productionAdminOf();
        $companyId = $this->admin->company_id;
        $this->store = OnlineStore::factory()->create(['company_id' => $companyId, 'enabled' => true, 'slug' => 'lanchonete', 'accept_minutes' => 15, 'whatsapp' => '11977776666']);
        (new OnlineStoreHour)->forceFill(['company_id' => $companyId, 'weekday' => 6, 'opens_at' => '18:00:00', 'closes_at' => '23:00:00'])->save();
        $this->burger = Product::factory()->create(['company_id' => $companyId, 'name' => 'X-Bacon', 'sale_price' => '28.00', 'track_stock' => false]);
        $this->zone = new DeliveryZone(['name' => 'Centro', 'fee' => '6.00']);
        $this->zone->company_id = $companyId;
        $this->zone->save();

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

    /** @return array{order: OnlineOrder, token: string} */
    private function place(string $type = 'delivery'): array
    {
        $payload = [
            'client_uuid' => (string) Str::uuid(),
            'type' => $type,
            'customer' => ['name' => 'Joana Silva', 'phone' => '11988887777'],
            'items' => [['product_id' => $this->burger->id, 'quantity' => 2]],
            'payment_method' => 'pix',
        ];
        if ($type === 'delivery') {
            $payload['address'] = ['street' => 'Rua das Flores', 'number' => '45', 'delivery_zone_id' => $this->zone->id];
        }

        return app(PlaceOnlineOrder::class)->handle($this->store, $payload);
    }

    /** @return array<string, mixed> */
    private function progress(string $token, string $slug = 'lanchonete'): array
    {
        return $this->getJson(route('shop.status', [$slug, $token]))->assertOk()->json('progress');
    }

    /**
     * @param  array<string, mixed>  $data
     * @return list<string>
     */
    private function doneKeys(array $data): array
    {
        return collect($data['steps'])->where('done', true)->pluck('key')->values()->all();
    }

    private function work(DeliveryOrder $delivery): ProductionWork
    {
        return ProductionWork::whereIn('delivery_order_item_id', $delivery->items()->pluck('id'))->firstOrFail();
    }

    private function advanceWork(DeliveryOrder $delivery, string $target): void
    {
        $this->actingAs($this->admin);
        $work = $this->work($delivery);
        app(TransitionProduction::class)->handle($work, $work->version, $target);
    }

    public function test_progress_pending_then_accepted_preparing_out_delivered(): void
    {
        ['order' => $online, 'token' => $token] = $this->place();

        $response = $this->getJson(route('shop.status', ['lanchonete', $token]))->assertOk();
        $this->assertSame('Joana', $response->json('customer_first_name'));
        $data = $response->json('progress');
        $this->assertSame('pending', $data['state']);
        $this->assertSame(['received'], $this->doneKeys($data));
        $this->assertSame(['received', 'accepted', 'preparing', 'ready', 'delivered'], array_column($data['steps'], 'key'));
        $this->assertSame('Saiu para entrega', $data['steps'][3]['label']);
        $this->assertSame('Entregue', $data['steps'][4]['label']);
        $this->assertNotNull($data['steps'][0]['at']);

        $this->actingAs($this->admin);
        $delivery = app(AcceptOnlineOrder::class)->handle($online);
        $data = $this->progress($token);
        $this->assertSame(['received', 'accepted'], $this->doneKeys($data));
        $this->assertNotNull($data['steps'][1]['at']);

        $this->advanceWork($delivery, 'preparing');
        $data = $this->progress($token);
        $this->assertSame(['received', 'accepted', 'preparing'], $this->doneKeys($data));
        $this->assertTrue($data['steps'][2]['current']);

        $this->advanceWork($delivery, 'ready');
        $this->actingAs($this->admin);
        $courier = User::factory()->create(['company_id' => $this->admin->company_id]);
        app(AdvanceDeliveryOrder::class)->handle($delivery->fresh(), DeliveryOrder::READY, $courier);
        $data = $this->progress($token);
        $this->assertSame(['received', 'accepted', 'preparing', 'ready'], $this->doneKeys($data));
        $this->assertNotNull($data['steps'][3]['at']);

        $this->actingAs($this->admin);
        app(AdvanceDeliveryOrder::class)->handle($delivery->fresh(), DeliveryOrder::OUT);
        $data = $this->progress($token);
        $this->assertSame('delivered', $data['state']);
        $this->assertSame(['received', 'accepted', 'preparing', 'ready', 'delivered'], $this->doneKeys($data));
        $this->assertNotNull($data['steps'][4]['at']);
        $this->assertSame('62.00', $data['total']);
        $this->assertSame('X-Bacon', $data['items'][0]['product_name']);
    }

    public function test_pickup_shows_ready_for_pickup_label(): void
    {
        ['order' => $online, 'token' => $token] = $this->place('pickup');
        $this->actingAs($this->admin);
        $delivery = app(AcceptOnlineOrder::class)->handle($online);
        $this->advanceWork($delivery, 'preparing');
        $this->advanceWork($delivery, 'ready');

        $data = $this->progress($token);

        $this->assertSame('pickup', $data['type']);
        $this->assertSame('Pronto para retirar', $data['steps'][3]['label']);
        $this->assertSame('Retirado', $data['steps'][4]['label']);
        $this->assertContains('ready', $this->doneKeys($data));
        $this->assertNotContains('delivered', $this->doneKeys($data));
    }

    public function test_rejected_and_expired_show_reason(): void
    {
        ['order' => $rejected, 'token' => $rejectedToken] = $this->place();
        $this->actingAs($this->admin);
        app(RejectOnlineOrder::class)->handle($rejected, 'Sem ingredientes.');
        $data = $this->progress($rejectedToken);
        $this->assertSame('rejected', $data['state']);
        $this->assertSame('Sem ingredientes.', $data['reason']);

        ['order' => $expired, 'token' => $expiredToken] = $this->place();
        $expired->forceFill(['status' => OnlineOrder::EXPIRED, 'decided_at' => now(), 'decision_reason' => 'Prazo de aceite encerrado.'])->save();
        $data = $this->progress($expiredToken);
        $this->assertSame('expired', $data['state']);
        $this->assertSame('A loja não confirmou a tempo.', $data['reason']);
    }

    public function test_cancelled_delivery_shows_cancel_reason(): void
    {
        ['order' => $online, 'token' => $token] = $this->place();
        $this->actingAs($this->admin);
        $delivery = app(AcceptOnlineOrder::class)->handle($online);
        $delivery->forceFill(['status' => DeliveryOrder::CANCELLED, 'cancelled_at' => now(), 'cancel_reason' => 'Cliente desistiu'])->save();

        $data = $this->progress($token);

        $this->assertSame('cancelled', $data['state']);
        $this->assertSame('Cliente desistiu', $data['reason']);
    }

    public function test_wrong_token_or_other_store_404(): void
    {
        ['token' => $token] = $this->place();
        $otherCompany = Company::factory()->create();
        OnlineStore::factory()->create(['company_id' => $otherCompany->id, 'enabled' => true, 'slug' => 'outra']);

        $this->get(route('shop.track', ['lanchonete', Str::random(48)]))->assertNotFound();
        $this->get(route('shop.track', ['lanchonete', 'curto']))->assertNotFound();
        $this->get(route('shop.track', ['lanchonete', str_repeat('-', 48)]))->assertNotFound();
        $this->get(route('shop.track', ['outra', $token]))->assertNotFound();
        $this->get(route('shop.track', ['inexistente', $token]))->assertNotFound();
        $this->getJson(route('shop.status', ['outra', $token]))->assertNotFound();
        $this->getJson(route('shop.status', ['lanchonete', Str::random(48)]))->assertNotFound();
    }

    public function test_track_page_still_works_when_store_is_disabled(): void
    {
        ['token' => $token] = $this->place();
        $this->store->forceFill(['enabled' => false])->save();

        $this->get(route('shop.track', ['lanchonete', $token]))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('shop/Track')
            ->where('store.name', $this->admin->company->trade_name)
            ->where('store.whatsapp', '11977776666')
            ->where('slug', 'lanchonete')
            ->where('token', $token)
            ->where('customer_first_name', 'Joana')
            ->where('progress.state', 'pending'));
    }

    public function test_status_payload_has_no_phone_or_address(): void
    {
        ['token' => $token] = $this->place();

        $response = $this->getJson(route('shop.status', ['lanchonete', $token]))->assertOk();

        $cacheControl = (string) $response->headers->get('Cache-Control');
        $this->assertStringContainsString('no-store', $cacheControl);
        $this->assertStringContainsString('private', $cacheControl);
        $body = (string) $response->getContent();
        foreach (['11988887777', 'Rua das Flores', 'Silva', 'phone', 'street', $token] as $secret) {
            $this->assertStringNotContainsString($secret, $body);
        }
        $this->assertSame(['store', 'customer_first_name', 'progress'], array_keys($response->json()));
        $this->assertSame(['name', 'whatsapp'], array_keys($response->json('store')));
    }
}
