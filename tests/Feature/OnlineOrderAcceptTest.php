<?php

namespace Tests\Feature;

use App\Actions\PlaceOnlineOrder;
use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\DeliveryOrder;
use App\Models\DeliveryZone;
use App\Models\OnlineOrder;
use App\Models\OnlineStore;
use App\Models\OnlineStoreHour;
use App\Models\Option;
use App\Models\OptionGroup;
use App\Models\Product;
use App\Models\ProductionSector;
use App\Models\ProductionWork;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class OnlineOrderAcceptTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private OnlineStore $store;

    private Product $burger;

    private Product $coke;

    private Option $bacon;

    private DeliveryZone $zone;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->productionAdminOf();
        $companyId = $this->admin->company_id;
        $this->store = OnlineStore::factory()->create(['company_id' => $companyId, 'enabled' => true, 'slug' => 'lanchonete', 'accept_minutes' => 15]);
        (new OnlineStoreHour)->forceFill(['company_id' => $companyId, 'weekday' => 6, 'opens_at' => '18:00:00', 'closes_at' => '23:00:00'])->save();
        $this->burger = Product::factory()->create(['company_id' => $companyId, 'name' => 'X-Bacon', 'sale_price' => '28.00', 'track_stock' => false]);
        $this->coke = Product::factory()->create(['company_id' => $companyId, 'name' => 'Coca', 'sale_price' => '10.00', 'track_stock' => false]);

        $group = new OptionGroup(['name' => 'Adicionais', 'kind' => 'extra', 'min_choices' => 0, 'max_choices' => 3, 'allows_quantity' => true, 'active' => true]);
        $group->company_id = $companyId;
        $group->save();
        $group->products()->attach($this->burger->id, ['company_id' => $companyId, 'position' => 0]);
        $this->bacon = new Option(['name' => 'Bacon', 'price' => '5.00', 'active' => true, 'position' => 0]);
        $this->bacon->company_id = $companyId;
        $this->bacon->option_group_id = $group->id;
        $this->bacon->save();

        $this->zone = new DeliveryZone(['name' => 'Centro', 'fee' => '6.00']);
        $this->zone->company_id = $companyId;
        $this->zone->save();

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

    private function place(string $phone = '11988887777'): OnlineOrder
    {
        return app(PlaceOnlineOrder::class)->handle($this->store, [
            'client_uuid' => (string) Str::uuid(),
            'type' => 'delivery',
            'customer' => ['name' => 'Joana', 'phone' => $phone],
            'address' => ['street' => 'Rua das Flores', 'number' => '45', 'complement' => 'Apto 2', 'reference' => 'Perto da praça', 'delivery_zone_id' => $this->zone->id],
            'items' => [
                ['product_id' => $this->burger->id, 'quantity' => 2, 'notes' => 'Sem cebola', 'options' => [['option_id' => $this->bacon->id]]],
                ['product_id' => $this->coke->id, 'quantity' => 1],
            ],
            'payment_method' => 'cash',
            'change_for' => '100',
            'notes' => 'Tocar a campainha',
        ])['order'];
    }

    private function accept(OnlineOrder $order, ?User $user = null)
    {
        return $this->actingAs($user ?? $this->admin)->post(route('online.orders.accept', $order->id));
    }

    public function test_accept_creates_delivery_with_snapshot_and_dispatches_production(): void
    {
        $customer = Customer::factory()->create(['company_id' => $this->admin->company_id]);
        $previous = new DeliveryOrder;
        $previous->forceFill(['company_id' => $this->admin->company_id, 'number' => 7, 'client_uuid' => (string) Str::uuid(), 'customer_id' => $customer->id,
            'type' => 'pickup', 'status' => DeliveryOrder::CANCELLED, 'customer_name' => $customer->name, 'subtotal' => '0', 'delivery_fee' => '0',
            'payment_method' => 'cash', 'user_id' => $this->admin->id, 'user_name' => $this->admin->name])->save();
        $online = $this->place();

        $this->accept($online)->assertSessionHasNoErrors()->assertRedirect();

        $delivery = DeliveryOrder::where('online_order_id', $online->id)->sole();
        $online->refresh();
        $this->assertSame(OnlineOrder::ACCEPTED, $online->status);
        $this->assertNotNull($online->decided_at);
        $this->assertSame($this->admin->id, $online->decided_by_id);
        $this->assertSame($this->admin->name, $online->decided_by_name);
        $this->assertSame(8, (int) $delivery->number);
        $this->assertSame(1, (int) $online->number);
        $this->assertSame(DeliveryOrder::RECEIVED, $delivery->status);
        $this->assertSame('delivery', $delivery->type);
        $this->assertSame($online->customer_id, $delivery->customer_id);
        $this->assertSame('Rua das Flores', $delivery->street);
        $this->assertSame('45', $delivery->number_address);
        $this->assertSame('Apto 2', $delivery->complement);
        $this->assertSame('Centro', $delivery->district);
        $this->assertSame('Perto da praça', $delivery->reference);
        $this->assertSame('76.00', (string) $delivery->subtotal);
        $this->assertSame((string) $online->subtotal, (string) $delivery->subtotal);
        $this->assertSame('6.00', (string) $delivery->delivery_fee);
        $this->assertSame('cash', $delivery->payment_method);
        $this->assertSame('100.00', (string) $delivery->change_for);
        $this->assertSame('Tocar a campainha', $delivery->notes);
        $this->assertSame($this->admin->id, $delivery->user_id);

        $lines = $delivery->items()->orderBy('id')->get();
        $snapshot = $online->items;
        $this->assertCount(2, $lines);
        foreach ($snapshot as $index => $line) {
            $this->assertSame($line->product_id, $lines[$index]->product_id);
            $this->assertSame($line->product_name, $lines[$index]->product_name);
            $this->assertSame((string) $line->unit_price, (string) $lines[$index]->unit_price);
            $this->assertSame((string) $line->total, (string) $lines[$index]->total);
            $this->assertSame($line->options_label, $lines[$index]->options_label);
            $this->assertEquals($line->options, $lines[$index]->options);
            $this->assertSame($line->notes, $lines[$index]->notes);
        }
        $this->assertSame('33.00', (string) $lines[0]->unit_price);
        $this->assertSame(2, ProductionWork::whereIn('delivery_order_item_id', $lines->pluck('id'))->count());
        $this->assertTrue(AuditLog::where('auditable_type', 'OnlineOrder')->where('auditable_id', $online->id)->where('user_id', $this->admin->id)->exists());
    }

    public function test_accept_is_idempotent(): void
    {
        $online = $this->place();

        $this->accept($online)->assertSessionHasNoErrors();
        $this->accept($online)->assertSessionHasNoErrors();

        $this->assertSame(1, DeliveryOrder::where('online_order_id', $online->id)->count());
        $this->assertSame(2, ProductionWork::count());
    }

    public function test_accept_refused_when_product_sold_out_after_order(): void
    {
        $online = $this->place();
        $this->coke->forceFill(['sold_out' => true])->save();

        $this->accept($online)->assertSessionHasErrors(['online_order' => 'Um item ficou indisponível. Recuse o pedido ou fale com o cliente.']);

        $this->assertSame(OnlineOrder::PENDING, $online->fresh()->status);
        $this->assertSame(0, DeliveryOrder::count());
        $this->assertSame(0, ProductionWork::count());
    }

    public function test_accept_refused_when_option_paused_after_order(): void
    {
        $online = $this->place();
        $this->bacon->forceFill(['paused' => true])->save();

        $this->accept($online)->assertSessionHasErrors(['online_order' => 'Um item ficou indisponível. Recuse o pedido ou fale com o cliente.']);

        $this->assertSame(OnlineOrder::PENDING, $online->fresh()->status);
        $this->assertSame(0, DeliveryOrder::count());
    }

    public function test_accept_after_deadline_marks_expired(): void
    {
        $online = $this->place();
        $this->at('2026-11-07 19:15');

        $this->accept($online)->assertSessionHasErrors(['online_order' => 'O prazo de aceite deste pedido venceu.']);

        $online->refresh();
        $this->assertSame(OnlineOrder::EXPIRED, $online->status);
        $this->assertSame('Prazo de aceite encerrado.', $online->decision_reason);
        $this->assertNotNull($online->decided_at);
        $this->assertSame(0, DeliveryOrder::count());

        $this->accept($online)->assertSessionHasErrors(['online_order' => 'Este pedido não está mais aguardando aceite.']);
    }

    public function test_reject_requires_reason_and_only_from_pending(): void
    {
        $online = $this->place();
        $url = route('online.orders.reject', $online->id);

        $this->actingAs($this->admin)->post($url, ['reason' => ''])->assertSessionHasErrors('reason');
        $this->post($url, ['reason' => 'ab'])->assertSessionHasErrors('reason');
        $this->post($url, ['reason' => str_repeat('a', 256)])->assertSessionHasErrors('reason');
        $this->assertSame(OnlineOrder::PENDING, $online->fresh()->status);

        $this->post($url, ['reason' => 'Sem entregador agora'])->assertSessionHasNoErrors();
        $online->refresh();
        $this->assertSame(OnlineOrder::REJECTED, $online->status);
        $this->assertSame('Sem entregador agora', $online->decision_reason);
        $this->assertSame($this->admin->id, $online->decided_by_id);
        $this->assertTrue(AuditLog::where('auditable_type', 'OnlineOrder')->where('auditable_id', $online->id)->where('user_id', $this->admin->id)->exists());

        $this->post($url, ['reason' => 'De novo'])->assertSessionHasErrors(['online_order' => 'Este pedido não está mais aguardando aceite.']);
        $this->accept($online)->assertSessionHasErrors(['online_order' => 'Este pedido não está mais aguardando aceite.']);

        $accepted = $this->place('11977776666');
        $this->accept($accepted)->assertSessionHasNoErrors();
        $this->post(route('online.orders.reject', $accepted->id), ['reason' => 'Mudei de ideia'])->assertSessionHasErrors('online_order');
        $this->assertSame(OnlineOrder::ACCEPTED, $accepted->fresh()->status);
        $this->assertSame(0, DeliveryOrder::where('online_order_id', $online->id)->count());
    }

    public function test_accept_and_reject_require_permission(): void
    {
        $online = $this->place();
        $clerk = $this->userWith(['deliveries.operate'], $this->admin->company);

        $this->accept($online, $clerk)->assertForbidden();
        $this->actingAs($clerk)->post(route('online.orders.reject', $online->id), ['reason' => 'Sem estoque'])->assertForbidden();
        $this->assertSame(OnlineOrder::PENDING, $online->fresh()->status);
    }

    public function test_cross_company_accept_404(): void
    {
        $online = $this->place();
        $intruder = $this->productionAdminOf();

        $this->accept($online, $intruder)->assertNotFound();
        $this->actingAs($intruder)->post(route('online.orders.reject', $online->id), ['reason' => 'Sem estoque'])->assertNotFound();

        $this->assertSame(OnlineOrder::PENDING, $online->fresh()->status);
        $this->assertSame(0, DeliveryOrder::withoutGlobalScopes()->count());
    }

    public function test_mark_ready_finishes_every_item_and_moves_order_to_ready(): void
    {
        $online = $this->place();
        $this->accept($online)->assertSessionHasNoErrors();
        $delivery = DeliveryOrder::where('online_order_id', $online->id)->sole();

        $this->actingAs($this->admin)->post(route('online.orders.ready', $online->id))->assertSessionHasNoErrors()->assertRedirect();

        $this->assertSame(DeliveryOrder::READY, $delivery->fresh()->status);
        $works = ProductionWork::whereIn('delivery_order_item_id', $delivery->items()->pluck('id'))->get();
        $this->assertCount(2, $works);
        $this->assertTrue($works->every(fn (ProductionWork $work) => $work->status === 'ready'));
    }

    public function test_mark_ready_requires_production_permission_and_an_accepted_order(): void
    {
        $online = $this->place();
        $this->actingAs($this->admin)->post(route('online.orders.ready', $online->id))->assertSessionHasErrors('online_order');

        $this->accept($online)->assertSessionHasNoErrors();
        $attendant = $this->userWith(['online_orders.accept'], $this->admin->company);
        $this->actingAs($attendant)->post(route('online.orders.ready', $online->id))->assertForbidden();

        $this->assertSame(DeliveryOrder::RECEIVED, DeliveryOrder::where('online_order_id', $online->id)->sole()->status);
    }

    public function test_history_search_finds_by_online_number(): void
    {
        $this->place('11911111111');
        $this->place('11922222222');
        $online = $this->place('11933333333');
        $this->accept($online)->assertSessionHasNoErrors();
        $delivery = DeliveryOrder::where('online_order_id', $online->id)->sole();

        $this->get(route('deliveries.history', ['search' => '3']))->assertInertia(fn (Assert $page) => $page
            ->has('orders.data', 1)
            ->where('orders.data.0.id', $delivery->id)
            ->where('orders.data.0.online_number', 3));

        $this->get(route('deliveries.history', ['search' => '9']))->assertInertia(fn (Assert $page) => $page->has('orders.data', 0));

        $this->get(route('deliveries.show', $delivery))->assertInertia(fn (Assert $page) => $page->where('order.online_number', 3));
        $this->get(route('deliveries.ticket', $delivery))->assertInertia(fn (Assert $page) => $page->where('order.online_number', 3));
        $this->get(route('deliveries.board'))->assertInertia(fn (Assert $page) => $page->where('orders.0.online_number', 3));
    }

    private function autoAccept(bool $enabled, ?User $user = null)
    {
        return $this->actingAs($user ?? $this->admin)->put(route('online.auto-accept'), ['enabled' => $enabled]);
    }

    /** O cliente da loja não está logado: o pedido nasce sem usuário autenticado. */
    private function placeAsGuest(): OnlineOrder
    {
        Auth::forgetUser();

        return $this->place();
    }

    public function test_auto_accept_accepts_new_orders_and_dispatches_production(): void
    {
        $this->autoAccept(true)->assertSessionHasNoErrors()->assertRedirect();
        $this->assertTrue($this->store->fresh()->auto_accept);

        $online = $this->placeAsGuest()->fresh();

        $this->assertSame(OnlineOrder::ACCEPTED, $online->status);
        $this->assertNull($online->decided_by_id);
        $this->assertSame('Automático', $online->decided_by_name);
        $delivery = DeliveryOrder::where('online_order_id', $online->id)->sole();
        $this->assertNull($delivery->user_id);
        $this->assertSame('Automático', $delivery->user_name);
        $this->assertSame(DeliveryOrder::RECEIVED, $delivery->status);
        $this->assertSame(2, ProductionWork::whereIn('delivery_order_item_id', $delivery->items()->pluck('id'))->count());
        $this->assertSame(['Automático'], ProductionWork::query()->join('production_events', 'production_events.production_work_id', '=', 'production_works.id')->distinct()->pluck('actor_name')->all());
    }

    public function test_auto_accept_leaves_order_pending_when_it_cannot_accept(): void
    {
        $this->autoAccept(true)->assertSessionHasNoErrors();
        ProductionSector::whereKey($this->admin->company->default_production_sector_id)->update(['active' => false]);

        $online = $this->placeAsGuest()->fresh();

        $this->assertSame(OnlineOrder::PENDING, $online->status);
        $this->assertSame(0, DeliveryOrder::count());
        $this->assertSame(0, ProductionWork::count());
    }

    public function test_auto_accept_off_keeps_orders_waiting(): void
    {
        $this->autoAccept(true)->assertSessionHasNoErrors();
        $this->autoAccept(false)->assertSessionHasNoErrors();

        $this->assertFalse($this->store->fresh()->auto_accept);
        $this->assertSame(OnlineOrder::PENDING, $this->placeAsGuest()->fresh()->status);
    }

    public function test_auto_accept_requires_active_default_sector_and_permission(): void
    {
        $this->admin->company->forceFill(['default_production_sector_id' => null])->save();
        $this->autoAccept(true)->assertSessionHasErrors(['auto_accept' => 'Cadastre um setor de produção padrão ativo antes de ativar o aceite automático.']);

        $clerk = $this->userWith(['deliveries.operate'], $this->admin->company);
        $this->autoAccept(true, $clerk)->assertForbidden();

        $this->assertFalse($this->store->fresh()->auto_accept);
    }
}
