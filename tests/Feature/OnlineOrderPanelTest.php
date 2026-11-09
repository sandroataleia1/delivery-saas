<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\DeliveryOrder;
use App\Models\OnlineOrder;
use App\Models\OnlineOrderItem;
use App\Models\OnlineStore;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class OnlineOrderPanelTest extends TestCase
{
    use RefreshDatabase;

    private function makeOrder(int $companyId, int $number, string $status = OnlineOrder::PENDING, ?string $expiresIn = '+10 minutes'): OnlineOrder
    {
        $order = new OnlineOrder;
        $order->forceFill([
            'company_id' => $companyId, 'number' => $number, 'client_uuid' => (string) Str::uuid(),
            'tracking_token' => 'secret-token-'.$number, 'tracking_token_hash' => hash('sha256', 'secret-token-'.$number.$companyId),
            'content_hash' => hash('sha256', 'content'.$number.$companyId), 'status' => $status, 'type' => 'delivery',
            'customer_name' => 'Joana', 'customer_phone' => '11988887777', 'street' => 'Rua A', 'number_address' => '1',
            'zone_name' => 'Centro', 'subtotal' => '20.00', 'delivery_fee' => '5.00', 'total' => '25.00', 'payment_method' => 'pix',
            'expires_at' => now()->modify($expiresIn),
        ])->save();
        $item = new OnlineOrderItem;
        $item->forceFill(['company_id' => $companyId, 'online_order_id' => $order->id, 'product_id' => Product::factory()->create(['company_id' => $companyId])->id,
            'product_name' => 'X-Bacon', 'quantity' => 1, 'unit_price' => '20.00', 'total' => '20.00', 'options_label' => '+ Bacon', 'notes' => 'Sem cebola'])->save();

        return $order;
    }

    public function test_panel_lists_pending_of_own_company_only(): void
    {
        $admin = $this->productionAdminOf();
        OnlineStore::factory()->create(['company_id' => $admin->company_id]);
        $this->makeOrder($admin->company_id, 1);
        $other = $this->productionAdminOf();
        $this->makeOrder($other->company_id, 1);

        $this->actingAs($admin)->get(route('online.orders'))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('online/Orders')
            ->has('pending', 1)
            ->where('pending.0.number', 1)
            ->where('pending.0.items.0.options_label', '+ Bacon')
            ->has('store')
            ->has('operation'));
    }

    public function test_panel_props_have_no_cost_or_token(): void
    {
        $admin = $this->productionAdminOf();
        OnlineStore::factory()->create(['company_id' => $admin->company_id]);
        $this->makeOrder($admin->company_id, 1);
        $this->makeOrder($admin->company_id, 2, OnlineOrder::REJECTED);

        $json = json_encode($this->actingAs($admin)->get(route('online.orders'))->viewData('page')['props']);

        foreach (['tracking_token', 'cost', 'content_hash', 'secret-token'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $json);
        }
    }

    private function makeDelivery(OnlineOrder $online, int $number, string $status): DeliveryOrder
    {
        $customer = Customer::factory()->create(['company_id' => $online->company_id]);
        $delivery = new DeliveryOrder;
        $delivery->forceFill(['company_id' => $online->company_id, 'number' => $number, 'client_uuid' => (string) Str::uuid(), 'customer_id' => $customer->id,
            'online_order_id' => $online->id, 'type' => 'delivery', 'status' => $status, 'customer_name' => 'Joana', 'subtotal' => '20.00',
            'delivery_fee' => '5.00', 'payment_method' => 'pix', 'user_name' => 'Loja online'])->save();

        return $delivery;
    }

    public function test_panel_splits_orders_into_analysis_production_and_ready(): void
    {
        $admin = $this->productionAdminOf();
        OnlineStore::factory()->create(['company_id' => $admin->company_id]);
        $this->makeOrder($admin->company_id, 1);
        $this->makeDelivery($this->makeOrder($admin->company_id, 2, OnlineOrder::ACCEPTED), 20, DeliveryOrder::RECEIVED);
        $this->makeDelivery($this->makeOrder($admin->company_id, 3, OnlineOrder::ACCEPTED), 30, DeliveryOrder::PREPARING);
        $this->makeDelivery($this->makeOrder($admin->company_id, 4, OnlineOrder::ACCEPTED), 40, DeliveryOrder::READY);
        $this->makeDelivery($this->makeOrder($admin->company_id, 5, OnlineOrder::ACCEPTED), 50, DeliveryOrder::OUT);
        $this->makeOrder($admin->company_id, 6, OnlineOrder::REJECTED)->forceFill(['decided_at' => now()])->save();

        $this->actingAs($admin)->get(route('online.orders'))->assertInertia(fn (Assert $page) => $page
            ->has('pending', 1)
            ->where('producing.0.number', 2)
            ->where('producing.1.number', 3)
            ->has('producing', 2)
            ->where('producing.1.delivery.status', 'preparing')
            ->has('ready', 1)
            ->where('ready.0.number', 4)
            ->where('ready.0.delivery.number', 40)
            ->where('ready.0.delivery.next_status', 'out_for_delivery')
            ->has('closed', 1)
            ->where('closed.0.number', 6)
            ->has('couriers'));
    }

    public function test_panel_requires_permission(): void
    {
        $courier = $this->userWith(['deliveries.courier']);

        $this->actingAs($courier)->get(route('online.orders'))->assertForbidden();
    }

    public function test_panel_without_store_has_null_store(): void
    {
        $admin = $this->productionAdminOf();

        $this->actingAs($admin)->get(route('online.orders'))->assertInertia(fn (Assert $page) => $page->where('store', null)->has('pending', 0));
    }

    public function test_shared_pending_count_only_for_permitted_users(): void
    {
        $admin = $this->productionAdminOf();
        $this->makeOrder($admin->company_id, 1);
        $this->makeOrder($admin->company_id, 2, OnlineOrder::PENDING, '-1 minute');
        $this->makeOrder($admin->company_id, 3, OnlineOrder::ACCEPTED);
        $courier = $this->userWith(['deliveries.courier', 'deliveries.operate'], $admin->company);

        $this->actingAs($admin)->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page->where('onlinePendingCount', 1));
        $this->actingAs($courier)->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page->where('onlinePendingCount', null));
    }
}
