<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Customer;
use App\Models\DeliveryOrder;
use App\Models\DeliveryZone;
use App\Models\OnlineOrder;
use App\Models\OnlineStore;
use App\Models\OnlineStoreHour;
use App\Models\Option;
use App\Models\OptionGroup;
use App\Models\Product;
use App\Models\ProductionWork;
use App\Models\Sale;
use App\Models\StockMovement;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class OnlineOrderPlaceTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private OnlineStore $store;

    private Product $burger;

    private Product $coke;

    private DeliveryZone $zone;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::factory()->create();
        $this->store = OnlineStore::factory()->create(['company_id' => $this->company->id, 'enabled' => true, 'slug' => 'lanchonete', 'accept_minutes' => 15]);
        $this->shift(6, '18:00:00', '23:00:00');
        $this->burger = Product::factory()->create(['company_id' => $this->company->id, 'name' => 'X-Bacon', 'sale_price' => '28.00', 'stock_quantity' => '10']);
        $this->coke = Product::factory()->create(['company_id' => $this->company->id, 'name' => 'Coca', 'sale_price' => '10.00', 'stock_quantity' => '10']);
        $this->zone = $this->zoneFor($this->company, 'Centro', '6.00');
        $this->at('2026-11-07 19:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    private function at(string $local): void
    {
        $moment = CarbonImmutable::parse($local, 'America/Sao_Paulo');
        Carbon::setTestNow($moment);
        CarbonImmutable::setTestNow($moment);
    }

    private function shift(int $weekday, string $opens, string $closes): void
    {
        (new OnlineStoreHour)->forceFill(['company_id' => $this->company->id, 'weekday' => $weekday, 'opens_at' => $opens, 'closes_at' => $closes])->save();
    }

    private function zoneFor(Company $company, string $name, string $fee): DeliveryZone
    {
        $zone = new DeliveryZone(['name' => $name, 'fee' => $fee]);
        $zone->company_id = $company->id;
        $zone->save();

        return $zone;
    }

    /** @param  array<string, mixed>  $overrides */
    private function payload(array $overrides = []): array
    {
        return [
            'client_uuid' => (string) Str::uuid(),
            'type' => 'delivery',
            'customer' => ['name' => 'Joana', 'phone' => '(11) 98888-7777'],
            'address' => ['street' => 'Rua das Flores', 'number' => '45', 'complement' => 'Apto 2', 'reference' => 'Perto da praça', 'delivery_zone_id' => $this->zone->id],
            'items' => [
                ['product_id' => $this->burger->id, 'quantity' => 2, 'notes' => 'Sem cebola'],
                ['product_id' => $this->coke->id, 'quantity' => 1],
            ],
            'payment_method' => 'pix',
            'notes' => 'Tocar a campainha',
            ...$overrides,
        ];
    }

    /** @param  array<string, mixed>  $payload */
    private function place(array $payload, string $slug = 'lanchonete'): TestResponse
    {
        return $this->postJson("/loja/{$slug}/pedidos", $payload);
    }

    public function test_places_delivery_order_with_server_prices(): void
    {
        $response = $this->place($this->payload([
            'delivery_fee' => 0,
            'total' => 1,
            'items' => [
                ['product_id' => $this->burger->id, 'quantity' => 2, 'unit_price' => 1],
                ['product_id' => $this->coke->id, 'quantity' => 1],
            ],
        ]));

        $response->assertCreated()->assertJsonPath('number', 1);
        $this->assertSame(['number', 'tracking_url'], array_keys($response->json()));

        $order = OnlineOrder::withoutGlobalScopes()->sole();
        $this->assertSame('66.00', $order->subtotal);
        $this->assertSame('6.00', $order->delivery_fee);
        $this->assertSame('72.00', $order->total);
        $this->assertSame(OnlineOrder::PENDING, $order->status);
        $this->assertSame(1, (int) $order->number);
        $this->assertSame($this->company->id, $order->company_id);
        $this->assertSame('Centro', $order->district);
        $this->assertSame('Centro', $order->zone_name);
        $this->assertSame('11988887777', $order->customer_phone);
        $this->assertTrue($order->expires_at->equalTo(now()->addMinutes(15)));
        $this->assertSame('28.00', $order->items[0]->unit_price);
        $this->assertSame('56.00', $order->items[0]->total);
        $this->assertSame('X-Bacon', $order->items[0]->product_name);
        $this->assertSame(url("/loja/lanchonete/pedido/{$order->tracking_token}"), $response->json('tracking_url'));
        $this->assertSame(hash('sha256', $order->tracking_token), $order->tracking_token_hash);

        $this->assertSame(0, DeliveryOrder::withoutGlobalScopes()->count());
        $this->assertSame(0, ProductionWork::withoutGlobalScopes()->count());
        $this->assertSame(0, Sale::withoutGlobalScopes()->count());
        $this->assertSame(0, StockMovement::withoutGlobalScopes()->count());
    }

    public function test_numbering_is_per_company(): void
    {
        $other = Company::factory()->create();
        $otherStore = OnlineStore::factory()->create(['company_id' => $other->id, 'enabled' => true, 'slug' => 'outra']);
        (new OnlineStoreHour)->forceFill(['company_id' => $other->id, 'weekday' => 6, 'opens_at' => '18:00:00', 'closes_at' => '23:00:00'])->save();
        $otherProduct = Product::factory()->create(['company_id' => $other->id, 'sale_price' => '40.00']);

        $this->place($this->payload(['customer' => ['name' => 'Joana', 'phone' => '11977776666']]))->assertCreated()->assertJsonPath('number', 1);
        $this->place([
            'client_uuid' => (string) Str::uuid(), 'type' => 'pickup', 'customer' => ['name' => 'Rui', 'phone' => '11977776666'],
            'items' => [['product_id' => $otherProduct->id, 'quantity' => 1]], 'payment_method' => 'cash',
        ], $otherStore->slug)->assertCreated()->assertJsonPath('number', 1);
        $this->place($this->payload())->assertCreated()->assertJsonPath('number', 2);

        $this->assertSame(1, Customer::withoutGlobalScopes()->where('company_id', $other->id)->count());
        $this->assertSame(2, Customer::withoutGlobalScopes()->where('company_id', $this->company->id)->count());
    }

    public function test_phone_formats_resolve_same_customer(): void
    {
        $legacy = Customer::factory()->create(['company_id' => $this->company->id, 'name' => 'Joana Antiga', 'phone' => '(11) 98888-7777']);
        Customer::factory()->create(['company_id' => Company::factory()->create()->id, 'phone' => '11988887777']);

        foreach (['(11) 98888-7777', '+55 11 98888-7777', '11988887777'] as $phone) {
            $this->place($this->payload(['customer' => ['name' => 'Outro nome', 'phone' => $phone]]))->assertCreated();
        }

        $this->assertSame(1, Customer::withoutGlobalScopes()->where('company_id', $this->company->id)->count());
        $this->assertSame([$legacy->id], OnlineOrder::withoutGlobalScopes()->distinct()->pluck('customer_id')->all());
        $this->assertSame('Joana Antiga', $legacy->fresh()->name);
        $this->assertSame(1, $legacy->addresses()->count());
        $this->assertSame('Centro', $legacy->addresses()->first()->district);
    }

    public function test_new_phone_creates_customer_with_normalized_phone(): void
    {
        foreach (['(11) 98888-7777', '+55 11 98888-7777', '11988887777'] as $phone) {
            $this->place($this->payload(['customer' => ['name' => 'Joana', 'phone' => $phone]]))->assertCreated();
        }

        $customer = Customer::withoutGlobalScopes()->sole();
        $this->assertSame('11988887777', $customer->phone);
        $this->assertSame('Joana', $customer->name);
        $this->assertSame($this->company->id, $customer->company_id);
    }

    public function test_invalid_phone_rejected(): void
    {
        $this->place($this->payload(['customer' => ['name' => 'Joana', 'phone' => '98888-7777']]))
            ->assertUnprocessable()->assertJsonValidationErrors(['customer.phone' => 'Informe um WhatsApp com DDD.']);
    }

    public function test_closed_store_refuses_even_if_page_was_open(): void
    {
        $this->at('2026-11-07 23:00');

        $this->place($this->payload())->assertUnprocessable()->assertJsonValidationErrors(['store' => 'A loja está fechada agora.']);
        $this->assertSame(0, OnlineOrder::withoutGlobalScopes()->count());
    }

    public function test_paused_store_refuses(): void
    {
        $this->store->forceFill(['paused_at' => now()])->save();

        $this->place($this->payload())->assertUnprocessable()->assertJsonValidationErrors(['store']);
    }

    public function test_unknown_or_disabled_store_is_not_found(): void
    {
        $this->place($this->payload(), 'nao-existe')->assertNotFound();

        $this->store->forceFill(['enabled' => false])->save();
        $this->place($this->payload())->assertNotFound();

        $this->store->forceFill(['enabled' => true])->save();
        $this->company->forceFill(['active' => false])->save();
        $this->place($this->payload())->assertNotFound();
    }

    public function test_overnight_shift_accepts_after_midnight(): void
    {
        $this->shift(6, '23:00:00', '02:00:00');
        $this->at('2026-11-08 01:30');

        $this->place($this->payload())->assertCreated();
    }

    public function test_zone_from_other_company_rejected(): void
    {
        $foreign = $this->zoneFor(Company::factory()->create(), 'Longe', '1.00');

        $this->place($this->payload(['address' => ['street' => 'Rua A', 'number' => '1', 'delivery_zone_id' => $foreign->id]]))
            ->assertUnprocessable()->assertJsonValidationErrors(['address.delivery_zone_id' => 'Não entregamos neste bairro.']);
        $this->assertSame(0, OnlineOrder::withoutGlobalScopes()->count());
    }

    public function test_type_not_accepted_and_pickup_ignores_address(): void
    {
        $this->store->forceFill(['accepts_delivery' => false])->save();
        $this->place($this->payload())->assertUnprocessable()->assertJsonValidationErrors(['type' => 'A loja não aceita este tipo de pedido.']);

        $this->place($this->payload(['type' => 'pickup']))->assertCreated();
        $order = OnlineOrder::withoutGlobalScopes()->sole();
        $this->assertNull($order->street);
        $this->assertNull($order->delivery_zone_id);
        $this->assertSame('0.00', $order->delivery_fee);
        $this->assertSame('66.00', $order->total);
    }

    public function test_product_from_other_company_or_sold_out_rejected(): void
    {
        $foreign = Product::factory()->create(['company_id' => Company::factory()->create()->id]);
        $this->place($this->payload(['items' => [['product_id' => $foreign->id, 'quantity' => 1]]]))
            ->assertUnprocessable()->assertJsonValidationErrors(['items.0.product_id' => 'Produto indisponível.']);

        $this->coke->forceFill(['sold_out' => true])->save();
        $this->place($this->payload())->assertUnprocessable()->assertJsonValidationErrors(['items.1.product_id']);
    }

    public function test_option_from_other_company_rejected(): void
    {
        $other = Company::factory()->create();
        $group = new OptionGroup(['name' => 'Adicionais', 'kind' => 'extra', 'min_choices' => 0, 'max_choices' => 1, 'allows_quantity' => false, 'active' => true]);
        $group->company_id = $other->id;
        $group->save();
        $group->products()->attach($this->burger->id, ['company_id' => $other->id, 'position' => 0]);
        $option = new Option(['name' => 'Bacon', 'price' => '5.00', 'active' => true, 'position' => 0]);
        $option->company_id = $other->id;
        $option->option_group_id = $group->id;
        $option->save();

        $this->place($this->payload(['items' => [['product_id' => $this->burger->id, 'quantity' => 1, 'options' => [['option_id' => $option->id]]]]]))
            ->assertUnprocessable()->assertJsonValidationErrors(['items.0']);
    }

    public function test_minimum_order_enforced_on_subtotal(): void
    {
        $this->store->forceFill(['min_order' => '30.00'])->save();

        $this->place($this->payload(['items' => [['product_id' => $this->coke->id, 'quantity' => 2]]]))
            ->assertUnprocessable()->assertJsonValidationErrors(['items' => 'Pedido mínimo de R$ 30,00.']);

        $this->place($this->payload(['items' => [['product_id' => $this->coke->id, 'quantity' => 3]]]))->assertCreated();
    }

    public function test_change_for_must_cover_total(): void
    {
        $this->place($this->payload(['payment_method' => 'cash', 'change_for' => 50]))
            ->assertUnprocessable()->assertJsonValidationErrors(['change_for' => 'O troco deve ser maior que o total.']);

        $this->place($this->payload(['payment_method' => 'cash', 'change_for' => 100]))->assertCreated();
        $this->place($this->payload(['payment_method' => 'pix', 'change_for' => 100]))->assertCreated();

        $this->assertSame(['100.00', null], OnlineOrder::withoutGlobalScopes()->orderBy('id')->pluck('change_for')->all());
    }

    public function test_replay_same_uuid_returns_same_link_and_different_content_is_409(): void
    {
        $payload = $this->payload();

        $first = $this->place($payload)->assertCreated();
        $this->place($payload)->assertOk()->assertExactJson($first->json());

        $this->at('2026-11-07 23:30');
        $this->place($payload)->assertOk()->assertJsonPath('tracking_url', $first->json('tracking_url'));

        $this->place([...$payload, 'notes' => 'Outra coisa'])->assertStatus(409);
        $this->assertSame(1, OnlineOrder::withoutGlobalScopes()->count());
    }

    public function test_pending_limit_per_phone(): void
    {
        foreach (range(1, 3) as $attempt) {
            $this->place($this->payload())->assertCreated();
        }

        $this->place($this->payload(['customer' => ['name' => 'Joana', 'phone' => '+55 (11) 98888-7777']]))
            ->assertUnprocessable()->assertJsonValidationErrors(['customer.phone' => 'Você já tem pedidos aguardando a loja. Aguarde a resposta.']);

        $this->place($this->payload(['customer' => ['name' => 'Outra', 'phone' => '11977776666']]))->assertCreated();
    }

    public function test_response_never_contains_address(): void
    {
        $customer = Customer::factory()->create(['company_id' => $this->company->id, 'phone' => '11988887777']);
        $address = $customer->addresses()->make(['street' => 'Rua Secreta', 'number' => '99', 'district' => 'Centro']);
        $address->company_id = $this->company->id;
        $address->save();

        $response = $this->place($this->payload(['type' => 'pickup', 'address' => null]))->assertCreated();

        $this->assertStringNotContainsString('Rua Secreta', $response->getContent());
        $this->assertStringNotContainsString('street', $response->getContent());
        $this->assertStringNotContainsString('addresses', $response->getContent());
    }

    public function test_guest_is_audit_actor(): void
    {
        $this->place($this->payload())->assertCreated();

        $this->assertDatabaseHas('audit_logs', ['company_id' => $this->company->id, 'auditable_type' => 'OnlineOrder', 'user_name' => 'Cliente (loja online)']);
    }

    public function test_throttles_by_phone_in_any_format(): void
    {
        foreach (['(11) 98888-7777', '11988887777', '+55 11 98888-7777', '11 988887777', '5511988887777'] as $phone) {
            $this->place($this->payload(['customer' => ['name' => 'Joana', 'phone' => $phone], 'items' => []]))->assertUnprocessable();
        }

        $this->place($this->payload())->assertTooManyRequests();
        $this->place($this->payload(['customer' => ['name' => 'Rui', 'phone' => '11977776666']]))->assertCreated();
    }
}
