<?php

namespace Tests\Feature;

use App\Models\Bill;
use App\Models\Company;
use App\Models\Customer;
use App\Models\DeliveryOrder;
use App\Models\OnlineOrder;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\SalePayment;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-11-09 20:00'));
        $this->admin = $this->adminOf();
        $this->product = Product::factory()->create(['company_id' => $this->admin->company_id, 'name' => 'Pizza', 'stock_quantity' => '100']);
    }

    private function sale(string $total, string $at, array $attributes = [], ?Company $company = null): Sale
    {
        static $number = 0;
        $number++;
        $companyId = ($company ?? $this->admin->company)->id;

        $sale = new Sale;
        $sale->forceFill([
            'company_id' => $companyId, 'number' => $number, 'client_uuid' => (string) Str::uuid(), 'subtotal' => $total, 'total' => $total,
            'status' => Sale::COMPLETED, 'user_name' => 'Ana', 'created_at' => CarbonImmutable::parse($at), ...$attributes,
        ])->save();
        (new SaleItem([
            'company_id' => $companyId, 'product_id' => $this->product->id, 'product_name' => 'Pizza', 'unit' => 'UN',
            'quantity' => '2', 'unit_price' => $total, 'unit_cost' => '0', 'total' => $total,
        ]))->forceFill(['sale_id' => $sale->id])->save();
        (new SalePayment(['method' => 'pix', 'amount' => $total]))->forceFill(['company_id' => $companyId, 'sale_id' => $sale->id])->save();

        return $sale;
    }

    private function delivery(string $status, ?Sale $sale = null): DeliveryOrder
    {
        static $number = 0;
        $number++;
        $delivery = new DeliveryOrder;
        $delivery->forceFill([
            'company_id' => $this->admin->company_id, 'number' => $number, 'client_uuid' => (string) Str::uuid(),
            'customer_id' => Customer::factory()->create(['company_id' => $this->admin->company_id])->id,
            'type' => DeliveryOrder::DELIVERY, 'status' => $status, 'customer_name' => 'Joana', 'subtotal' => '20.00',
            'delivery_fee' => '5.00', 'payment_method' => 'pix', 'user_name' => 'Ana', 'sale_id' => $sale?->id,
        ])->save();

        return $delivery;
    }

    public function test_sales_compare_today_with_same_weekday_last_week_and_month_with_last_month(): void
    {
        $this->sale('40.00', '2026-11-09 12:00');
        $this->sale('60.00', '2026-11-09 19:00', ['origin' => 'table']);
        $this->sale('500.00', '2026-11-09 13:00', ['status' => Sale::CANCELLED]);
        $this->sale('999.00', '2026-11-09 13:00', [], Company::factory()->create());
        $this->sale('50.00', '2026-11-02 18:00');
        $this->sale('70.00', '2026-11-02 21:00');
        $this->sale('30.00', '2026-10-05 10:00');
        $this->sale('80.00', '2026-10-20 10:00');

        $this->actingAs($this->admin)->get(route('dashboard'))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Dashboard')
            ->where('sales.today', ['total' => '100.00', 'count' => 2, 'average' => '50.00'])
            ->where('sales.last_week', ['total' => '50.00', 'count' => 1, 'average' => '50.00'])
            ->where('sales.month.total', '220.00')
            ->where('sales.last_month.total', '30.00'));
    }

    public function test_analytics_split_online_store_from_other_deliveries_and_rank_products(): void
    {
        $this->sale('40.00', '2026-11-09 12:00');
        $this->delivery(DeliveryOrder::DELIVERED, $this->sale('25.00', '2026-11-08 19:00', ['origin' => 'delivery']));
        $online = $this->delivery(DeliveryOrder::DELIVERED, $this->sale('35.00', '2026-11-07 19:00', ['origin' => 'delivery']));
        $online->forceFill(['online_order_id' => $this->onlineOrderId()])->save();

        $this->actingAs($this->admin)->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page
            ->has('analytics.daily', 14)
            ->where('analytics.daily.13', ['day' => '2026-11-09', 'label' => '09/11', 'total' => '40.00', 'count' => 1])
            ->where('analytics.channels', [
                ['label' => 'Balcão', 'total' => '40.00', 'count' => 1],
                ['label' => 'Loja online', 'total' => '35.00', 'count' => 1],
                ['label' => 'Entregas', 'total' => '25.00', 'count' => 1],
            ])
            ->where('analytics.top_products', [['label' => 'Pizza', 'quantity' => '6', 'total' => '100.00']])
            ->where('analytics.payments.0', ['label' => 'Pix', 'total' => '100.00', 'count' => 3]));
    }

    public function test_live_operation_counts_orders_in_each_stage(): void
    {
        $this->delivery(DeliveryOrder::RECEIVED);
        $this->delivery(DeliveryOrder::PREPARING);
        $this->delivery(DeliveryOrder::READY);
        $this->delivery(DeliveryOrder::OUT);
        $delivered = $this->delivery(DeliveryOrder::DELIVERED);
        $delivered->forceFill(['created_at' => now()->subMinutes(40), 'delivered_at' => now()->subMinutes(10)])->save();

        $this->actingAs($this->admin)->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page
            ->where('live.producing', 2)
            ->where('live.ready', 1)
            ->where('live.out', 1)
            ->where('live.delivery_minutes', 30)
            ->where('live.open_tables', 0));
    }

    public function test_each_block_follows_the_users_permissions(): void
    {
        $courierDesk = $this->userWith(['deliveries.operate'], $this->admin->company);

        $this->actingAs($courierDesk)->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page
            ->has('live.producing')
            ->missing('live.open_tables')
            ->missing('live.online_pending')
            ->where('sales', null)
            ->where('analytics', null)
            ->where('finance', null)
            ->where('lowStock', null)
            ->where('recent', []));
    }

    public function test_finance_and_stock_alerts_show_what_needs_attention(): void
    {
        Bill::factory()->create(['company_id' => $this->admin->company_id, 'amount' => '120.00', 'due_date' => '2026-11-05']);
        Bill::factory()->create(['company_id' => $this->admin->company_id, 'amount' => '80.00', 'due_date' => '2026-11-12']);
        Bill::factory()->create(['company_id' => $this->admin->company_id, 'amount' => '500.00', 'due_date' => '2026-12-30']);
        Bill::factory()->create(['company_id' => $this->admin->company_id, 'amount' => '45.00', 'due_date' => '2026-11-01', 'type' => Bill::RECEIVABLE]);
        Bill::factory()->create(['company_id' => $this->admin->company_id, 'amount' => '999.00', 'due_date' => '2026-11-01', 'status' => Bill::PAID]);
        Product::factory()->create(['company_id' => $this->admin->company_id, 'name' => 'Queijo', 'track_stock' => true, 'stock_quantity' => '1', 'min_stock' => '5']);

        $this->actingAs($this->admin)->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page
            ->where('finance.payable_overdue', ['count' => 1, 'total' => '120.00'])
            ->where('finance.payable_week', ['count' => 1, 'total' => '80.00'])
            ->where('finance.receivable_open', ['count' => 1, 'total' => '45.00'])
            ->where('finance.receivable_overdue', ['count' => 1, 'total' => '45.00'])
            ->where('lowStock.count', 1)
            ->where('lowStock.items.0.name', 'Queijo'));
    }

    private function onlineOrderId(): int
    {
        $order = new OnlineOrder;
        $order->forceFill([
            'company_id' => $this->admin->company_id, 'number' => 1, 'client_uuid' => (string) Str::uuid(),
            'tracking_token' => 'token', 'tracking_token_hash' => hash('sha256', 'token'), 'content_hash' => hash('sha256', 'content'),
            'status' => OnlineOrder::ACCEPTED, 'type' => 'delivery', 'customer_name' => 'Joana', 'customer_phone' => '11988887777',
            'street' => 'Rua A', 'number_address' => '1', 'subtotal' => '30.00', 'delivery_fee' => '5.00', 'total' => '35.00',
            'payment_method' => 'pix', 'expires_at' => now()->addMinutes(10),
        ])->save();

        return $order->id;
    }
}
