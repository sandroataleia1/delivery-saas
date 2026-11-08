<?php

namespace Tests\Feature;

use App\Actions\AddOrderItems;
use App\Actions\CreateDeliveryOrder;
use App\Actions\OpenTable;
use App\Actions\TransitionProduction;
use App\Models\Customer;
use App\Models\DiningTable;
use App\Models\Product;
use App\Models\ProductionSector;
use App\Models\ProductionWork;
use App\Models\Sale;
use App\Models\StockMovement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Tests\TestCase;

class ProductionFlowTest extends TestCase
{
    use RefreshDatabase;

    private function works(): array
    {
        $admin = $this->adminOf();
        $this->actingAs($admin);
        $sector = ProductionSector::factory()->create(['company_id' => $admin->company_id]);
        $admin->company->forceFill(['default_production_sector_id' => $sector->id])->save();
        $product = Product::factory()->create(['company_id' => $admin->company_id]);
        $order = app(OpenTable::class)->handle(DiningTable::factory()->create(['company_id' => $admin->company_id]), 1, $admin);
        app(AddOrderItems::class)->handle($order, [['product_id' => $product->id, 'quantity' => '1'], ['product_id' => $product->id, 'quantity' => '2']], (string) Str::uuid());

        return [$admin, $order, ...ProductionWork::orderBy('id')->get()->all()];
    }

    public function test_preparing_ready_and_delivery_are_independent_per_line(): void
    {
        [$admin, $order, $drink, $food] = $this->works();
        $action = app(TransitionProduction::class);
        $action->handle($drink, 0, 'preparing');
        $action->handle($drink, 1, 'ready');
        $action->handle($drink, 2, 'delivered');
        $this->assertNotNull($drink->fresh()->delivered_at);
        $this->assertSame('waiting', $food->fresh()->status);
        $this->assertSame(3, $drink->fresh()->events()->count() - 1);
        $this->assertSame(0, Sale::count());
        $this->assertSame(0, StockMovement::count());
    }

    public function test_stale_version_and_skips_are_refused(): void
    {
        [, , $work] = $this->works();
        $this->actingAs(Auth::user())->postJson('/producao/'.$work->id.'/etapa', ['expected_version' => 0, 'target' => 'ready'])->assertUnprocessable();
        $this->assertSame('waiting', $work->fresh()->status);
    }

    public function test_whole_order_cancel_after_preparing_requires_management(): void
    {
        [$admin, $order, $work] = $this->works();
        app(TransitionProduction::class)->handle($work, 0, 'preparing');
        $waiter = $this->userWith(['tables.cancel'], $admin->company);
        $this->actingAs($waiter)->postJson('/mesas/comandas/'.$order->id.'/cancelar', ['reason' => 'Cliente desistiu'])->assertForbidden();
        $this->assertTrue($order->fresh()->isOpen());
        $this->assertNull($work->fresh()->cancelled_at);
    }

    public function test_close_refuses_unfinished_production_without_sale(): void
    {
        [$admin, $order] = $this->works();
        $this->openCash($admin);
        $this->postJson('/mesas/comandas/'.$order->id.'/fechar', ['client_uuid' => (string) Str::uuid(), 'expected_subtotal' => '30.00', 'charge_service' => false, 'payments' => [['method' => 'pix', 'amount' => '30']]])->assertUnprocessable()->assertJsonValidationErrors('production');
        $this->assertTrue($order->fresh()->isOpen());
        $this->assertSame(0, Sale::count());
        $this->assertSame(0, StockMovement::count());
    }

    public function test_cancellation_notice_persists_until_explicit_acknowledgement(): void
    {
        [$admin, $order, $work] = $this->works();
        app(TransitionProduction::class)->handle($work, 0, 'preparing');
        $this->post('/mesas/comandas/'.$order->id.'/itens/'.$work->table_order_item_id.'/cancelar', ['reason' => 'Cliente desistiu'])->assertSessionHasNoErrors();
        $event = $work->events()->where('to_state', 'cancelled')->sole();
        $this->assertNull($event->acknowledged_at);
        $this->assertNotNull($work->fresh()->cancelled_at);
        $this->assertSame('20.00', $order->subtotal());
        $this->post('/producao/avisos/'.$event->id.'/ciencia')->assertSessionHasNoErrors();
        $this->post('/producao/avisos/'.$event->id.'/ciencia')->assertSessionHasNoErrors();
        $this->assertSame($admin->id, $event->fresh()->acknowledged_by);
        $this->assertSame(1, $work->events()->where('to_state', 'cancelled')->count());
        $this->assertSame(0, Sale::count());
        $this->assertSame(0, StockMovement::count());
    }

    public function test_delivered_lines_allow_close_once_and_print_cannot_advance_production(): void
    {
        [$admin, $order, $drink, $food] = $this->works();
        $this->openCash($admin);
        foreach ([$drink, $food] as $work) {
            app(TransitionProduction::class)->handle($work, 0, 'preparing');
            app(TransitionProduction::class)->handle($work, 1, 'ready');
            app(TransitionProduction::class)->handle($work, 2, 'delivered');
        }
        $payload = ['client_uuid' => (string) Str::uuid(), 'expected_subtotal' => '30.00', 'charge_service' => false, 'payments' => [['method' => 'pix', 'amount' => '30']]];
        $this->post('/mesas/comandas/'.$order->id.'/fechar', $payload)->assertSessionHasNoErrors();
        $this->post('/mesas/comandas/'.$order->id.'/fechar', $payload)->assertSessionHasNoErrors();
        $this->assertSame(1, Sale::count());
        $this->postJson('/mesas/comandas/'.$order->id.'/cozinha')->assertUnprocessable();
        $this->assertSame(3, $drink->fresh()->version);
    }

    public function test_stale_transition_does_not_skip_ready_or_deliver_twice(): void
    {
        [, , $work] = $this->works();
        $this->post('/producao/'.$work->id.'/etapa', ['expected_version' => 0, 'target' => 'preparing'])->assertSessionHasNoErrors();
        $this->postJson('/producao/'.$work->id.'/etapa', ['expected_version' => 0, 'target' => 'ready'])->assertUnprocessable();
        $this->assertSame('preparing', $work->fresh()->status);
        $this->assertSame(2, $work->events()->count());
    }

    public function test_delivery_status_is_derived_from_all_lines_and_old_shortcut_is_refused(): void
    {
        [$admin] = $this->works();
        $product = Product::where('company_id', $admin->company_id)->firstOrFail();
        $customer = Customer::factory()->create(['company_id' => $admin->company_id]);
        $order = app(CreateDeliveryOrder::class)->handle(['client_uuid' => (string) Str::uuid(), 'customer_id' => $customer->id, 'type' => 'pickup', 'payment_method' => 'pix', 'items' => [['product_id' => $product->id, 'quantity' => '1'], ['product_id' => $product->id, 'quantity' => '1']]]);
        $this->postJson('/entregas/'.$order->id.'/avancar', ['expected_status' => 'received'])->assertUnprocessable();
        $works = ProductionWork::whereNotNull('delivery_order_item_id')->orderBy('id')->get();
        app(TransitionProduction::class)->handle($works[0], 0, 'preparing');
        app(TransitionProduction::class)->handle($works[0], 1, 'ready');
        $this->assertSame('preparing', $order->fresh()->status);
        app(TransitionProduction::class)->handle($works[1], 0, 'preparing');
        app(TransitionProduction::class)->handle($works[1], 1, 'ready');
        $this->assertSame('ready', $order->fresh()->status);
        $this->post('/entregas/'.$order->id.'/avancar', ['expected_status' => 'ready'])->assertSessionHasNoErrors();
        $this->assertSame('delivered', $order->fresh()->status);
        $this->assertSame(2, ProductionWork::whereNotNull('delivery_order_item_id')->whereNotNull('delivered_at')->count());
        $this->assertSame(0, Sale::count());
        $this->assertSame(0, StockMovement::count());
    }
}
