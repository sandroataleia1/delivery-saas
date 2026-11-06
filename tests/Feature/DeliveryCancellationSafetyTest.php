<?php

namespace Tests\Feature;

use App\Actions\CancelDeliveryOrder;
use App\Models\Customer;
use App\Models\DeliveryOrder;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class DeliveryCancellationSafetyTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Product $product;

    private function order(): DeliveryOrder
    {
        $this->admin = $this->adminOf();
        $customer = Customer::factory()->create(['company_id' => $this->admin->company_id]);
        $this->product = Product::factory()->create(['company_id' => $this->admin->company_id, 'stock_quantity' => '10', 'sale_price' => '80']);
        $this->actingAs($this->admin)->post('/entregas', [
            'client_uuid' => (string) Str::uuid(), 'customer_id' => $customer->id, 'type' => 'delivery',
            'items' => [['product_id' => $this->product->id, 'quantity' => '1']],
            'address' => ['street' => 'Rua A', 'number' => '10', 'district' => 'Centro'],
            'delivery_fee' => '5', 'payment_method' => 'cash',
        ])->assertSessionHasNoErrors();

        return DeliveryOrder::sole();
    }

    private function effects(): array
    {
        $tables = ['sales', 'sale_items', 'sale_payments', 'bills', 'cash_movements', 'stock_movements', 'audit_logs'];

        return [
            'counts' => array_map(fn ($table) => DB::table($table)->count(), $tables),
            'stock' => $this->product->fresh()->stock_quantity,
        ];
    }

    public function test_delivered_unsettled_order_cannot_be_cancelled_or_change_financial_and_stock_data(): void
    {
        $order = $this->order();
        $order->forceFill(['status' => DeliveryOrder::DELIVERED, 'delivered_at' => now()])->save();
        $before = $this->effects();

        $this->post("/entregas/{$order->id}/cancelar", ['reason' => 'Tentativa indevida'])->assertSessionHasErrors('reason');

        $this->assertSame(DeliveryOrder::DELIVERED, $order->fresh()->status);
        $this->assertNull($order->fresh()->sale_id);
        $this->assertNull($order->fresh()->cancelled_at);
        $this->assertSame($before, $this->effects());
    }

    public function test_action_checks_persisted_delivery_state_instead_of_stale_object(): void
    {
        $order = $this->order();
        DeliveryOrder::whereKey($order->id)->update(['status' => DeliveryOrder::DELIVERED, 'delivered_at' => now()]);
        $before = $this->effects();

        try {
            app(CancelDeliveryOrder::class)->handle($order, 'Objeto antigo');
            $this->fail('Cancelamento deve reler o estado entregue.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('reason', $exception->errors());
        }

        $this->assertSame(DeliveryOrder::DELIVERED, $order->fresh()->status);
        $this->assertSame($before, $this->effects());
    }

    public function test_another_company_cannot_cancel_or_discover_the_delivered_order(): void
    {
        $order = $this->order();
        $order->forceFill(['status' => DeliveryOrder::DELIVERED, 'delivered_at' => now()])->save();
        $otherAdmin = $this->adminOf();
        $before = $this->effects();

        $this->actingAs($otherAdmin)->post("/entregas/{$order->id}/cancelar", ['reason' => 'Outra empresa'])->assertNotFound();

        $this->assertSame($before, $this->effects());
        $this->assertDatabaseHas('delivery_orders', ['id' => $order->id, 'status' => DeliveryOrder::DELIVERED]);
    }
}
