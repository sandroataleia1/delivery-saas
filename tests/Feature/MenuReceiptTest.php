<?php

namespace Tests\Feature;

use App\Actions\AddOrderItems;
use App\Actions\TransferTable;
use App\Models\Company;
use App\Models\DiningTable;
use App\Models\MenuOrderReceipt;
use App\Models\Product;
use App\Models\RestaurantOperationSettings;
use App\Models\TableOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class MenuReceiptTest extends TestCase
{
    use RefreshDatabase;

    public function test_replay_after_closure_does_not_open_another_attendance(): void
    {
        [$company, $table, $product, $data] = $this->menu();
        $this->postJson('/m/'.$table->qr_token, $data)->assertCreated();
        $admin = $this->productionAdminOf($company);
        $this->actingAs($admin)->postJson(route('receipts.confirm', MenuOrderReceipt::sole()->id))->assertOk();
        $order = TableOrder::sole();
        $this->openCash($admin);
        $this->deliverProduction($order);
        $this->post('/mesas/comandas/'.$order->id.'/fechar', ['client_uuid' => (string) Str::uuid(), 'expected_subtotal' => '20', 'charge_service' => false, 'payments' => [['method' => 'pix', 'amount' => '20']]])->assertSessionHasNoErrors();
        $this->assertNotSame($table->service_context, $table->fresh()->service_context);
        $product->forceFill(['active' => false, 'sale_price' => '99'])->save();
        $this->postJson('/m/'.$table->qr_token, $data)->assertOk()->assertJsonPath('receipt.status', 'confirmed')->assertJsonPath('receipt.total', '20.00');
        $this->assertDatabaseCount('table_orders', 1);
        $this->assertDatabaseCount('menu_order_receipts', 1);
        $this->assertDatabaseCount('sales', 1);
        $this->assertSame('8.000', $product->fresh()->stock_quantity);
    }

    public function test_pending_receipt_blocks_closure_even_with_staff_items_and_survives_transfer(): void
    {
        [$company, $table, $product, $data] = $this->menu();
        $this->postJson('/m/'.$table->qr_token, $data)->assertCreated();
        $admin = $this->productionAdminOf($company);
        $this->actingAs($admin);
        $order = TableOrder::sole();
        app(AddOrderItems::class)->handle($order, [['product_id' => $product->id, 'quantity' => 1]], (string) Str::uuid());
        $this->openCash($admin);
        $this->postJson('/mesas/comandas/'.$order->id.'/fechar', ['client_uuid' => (string) Str::uuid(), 'expected_subtotal' => '10', 'charge_service' => false, 'payments' => [['method' => 'pix', 'amount' => '10']]])->assertUnprocessable()->assertJsonValidationErrors('order');
        $this->assertDatabaseCount('sales', 0);
        $this->assertDatabaseCount('stock_movements', 0);
        $target = DiningTable::factory()->create(['company_id' => $company->id]);
        app(TransferTable::class)->handle($order, $target);
        $this->postJson(route('receipts.confirm', MenuOrderReceipt::sole()->id))->assertOk();
        $this->assertSame($target->id, $order->fresh()->open_table_id);
        $this->assertSame('30.00', $order->subtotal());
        $this->postJson('/m/'.$table->qr_token, [...$data, 'client_uuid' => (string) Str::uuid()])->assertUnprocessable()->assertJsonValidationErrors('service_context');
    }

    public function test_unavailable_product_requires_resolution_or_rejection_with_reason(): void
    {
        [$company, $table, $product, $data] = $this->menu();
        $this->postJson('/m/'.$table->qr_token, $data)->assertCreated();
        $receipt = MenuOrderReceipt::sole();
        $product->forceFill(['active' => false])->save();
        $this->actingAs($this->productionAdminOf($company))->postJson(route('receipts.confirm', $receipt))->assertUnprocessable();
        $this->postJson(route('receipts.reject', $receipt), ['reason' => ' '])->assertUnprocessable();
        $this->assertSame('pending', $receipt->fresh()->status);
        $this->postJson(route('receipts.reject', $receipt), ['reason' => 'Produto indisponível'])->assertOk();
        $this->assertSame('rejected', $receipt->fresh()->status);
        $this->assertDatabaseCount('table_order_items', 0);
    }

    public static function excessiveOrders(): array
    {
        return ['line units' => ['max_line_units', 1], 'order units' => ['max_order_units', 1], 'order value' => ['max_order_value', '19.99'], 'pending units' => ['max_pending_units', 1], 'pending value' => ['max_pending_value', '19.99']];
    }

    #[DataProvider('excessiveOrders')]
    public function test_limits_reject_without_opening_empty_attendance(string $field, mixed $limit): void
    {
        [$company, $table, $product, $data] = $this->menu();
        RestaurantOperationSettings::factory()->create(['company_id' => $company->id, $field => $limit]);
        $this->postJson('/m/'.$table->qr_token, $data)->assertUnprocessable()->assertJsonValidationErrors('items');
        foreach (['table_orders', 'menu_order_receipts', 'table_order_items', 'stock_movements', 'cash_movements'] as $name) {
            $this->assertDatabaseCount($name, 0);
        }
        $this->assertSame('10.000', $product->fresh()->stock_quantity);
    }

    public function test_policy_snapshot_is_preserved_and_tracking_key_is_required_for_every_receipt(): void
    {
        [$company, $table, $product, $data] = $this->menu();
        $this->postJson('/m/'.$table->qr_token, $data)->assertCreated();
        $snapshot = MenuOrderReceipt::sole()->policy_snapshot;
        $this->actingAs($this->productionAdminOf($company))->putJson('/empresa/operacao', ['confirmation_mode' => 'automatic', 'max_order_value' => 100])->assertOk();
        $this->assertSame($snapshot, MenuOrderReceipt::sole()->policy_snapshot);
        $this->withHeader('X-Menu-Receipt-Key', str_repeat('b', 64))->getJson('/m/'.$table->qr_token.'/envios/'.$data['client_uuid'])->assertNotFound();
        $this->postJson('/m/'.$table->qr_token, [...$data, 'tracking_key' => str_repeat('b', 64)])->assertNotFound();
        $this->assertDatabaseCount('menu_order_receipts', 1);
    }

    public function test_rate_budget_is_per_table_and_company_instead_of_global_ip(): void
    {
        [$company, $table] = $this->menu();
        $payload = ['client_uuid' => 'invalid', 'items' => []];
        foreach (range(1, 20) as $attempt) {
            $this->postJson('/m/'.$table->qr_token, $payload)->assertUnprocessable();
        }
        $this->postJson('/m/'.$table->qr_token, $payload)->assertTooManyRequests();
        [$other, $otherTable] = $this->menu();
        $this->postJson('/m/'.$otherTable->qr_token, $payload)->assertUnprocessable();
        $secondTable = DiningTable::factory()->create(['company_id' => $company->id]);
        $this->postJson('/m/'.$secondTable->qr_token, $payload)->assertUnprocessable();
        $this->assertDatabaseCount('menu_order_receipts', 0);
    }

    public function test_staff_props_show_pending_counts_and_snapshots_without_tracking_secrets(): void
    {
        [$company, $table, $product, $data] = $this->menu();
        $this->postJson('/m/'.$table->qr_token, $data)->assertCreated();
        $admin = $this->productionAdminOf($company);
        $this->actingAs($admin)->get('/mesas')->assertInertia(fn (AssertableInertia $page) => $page
            ->where('tables.0.order.pending_count', 1)->has('tables.0.order.pending_ids', 1)->has('operation.sound_alerts'));
        $this->get('/mesas/comandas/'.TableOrder::sole()->id)->assertInertia(fn (AssertableInertia $page) => $page
            ->where('receipts.0.status', 'pending')->where('receipts.0.items.0.product_name', $product->name)->where('receipts.0.items.0.options_label', null)
            ->missing('receipts.0.tracking_key_hash')->missing('receipts.0.content_hash'));
        $this->get('/empresa')->assertInertia(fn (AssertableInertia $page) => $page->where('operation.effective_mode', 'manual')->has('operationCeilings.max_lines'));
    }

    public function test_pending_alert_lists_only_pending_receipts_of_own_company_with_current_table(): void
    {
        [$company, $table, $product, $data] = $this->menu();
        $this->postJson('/m/'.$table->qr_token, $data)->assertCreated();
        $this->postJson('/m/'.$table->qr_token, [...$data, 'client_uuid' => (string) Str::uuid()])->assertCreated();
        $admin = $this->productionAdminOf($company);
        $this->actingAs($admin)->postJson(route('receipts.reject', MenuOrderReceipt::orderByDesc('id')->first()), ['reason' => 'Tarde'])->assertOk();
        $target = DiningTable::factory()->create(['company_id' => $company->id, 'number' => 42]);
        app(TransferTable::class)->handle(TableOrder::sole(), $target);
        $this->getJson(route('receipts.pending'))->assertOk()->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonCount(1, 'receipts')
            ->assertJsonPath('receipts.0.id', MenuOrderReceipt::orderBy('id')->first()->id)
            ->assertJsonPath('receipts.0.table', '42')
            ->assertJsonPath('receipts.0.order_id', TableOrder::sole()->id)
            ->assertJsonPath('receipts.0.units', 2);
        $this->actingAs($this->productionAdminOf())->getJson(route('receipts.pending'))->assertOk()->assertJsonCount(0, 'receipts');
        $this->actingAs($this->userWith(['tables.operate'], $company))->getJson(route('receipts.pending'))->assertForbidden();
    }

    public function test_menu_counts_pending_table_orders_of_own_company(): void
    {
        [$company, $table, $product, $data] = $this->menu();
        $admin = $this->productionAdminOf($company);
        $this->actingAs($admin)->getJson(route('nav.counts'))->assertJsonPath('tables', 0);

        $this->postJson('/m/'.$table->qr_token, $data)->assertCreated();
        $this->postJson('/m/'.$table->qr_token, [...$data, 'client_uuid' => (string) Str::uuid()])->assertCreated();
        $this->actingAs($admin)->postJson(route('receipts.reject', MenuOrderReceipt::orderByDesc('id')->first()), ['reason' => 'Tarde'])->assertOk();

        $this->getJson(route('nav.counts'))->assertJsonPath('tables', 1);
        $this->get(route('dashboard'))->assertInertia(fn (AssertableInertia $page) => $page->where('tablePendingCount', 1));
        $this->actingAs($this->productionAdminOf())->getJson(route('nav.counts'))->assertJsonPath('tables', 0);
        $this->actingAs($this->userWith(['tables.operate'], $company))->getJson(route('nav.counts'))->assertJsonPath('tables', null);
        $this->get(route('dashboard'))->assertInertia(fn (AssertableInertia $page) => $page->where('tablePendingCount', null));
    }

    private function menu(): array
    {
        $company = Company::factory()->create(['qr_menu_enabled' => true]);
        $table = DiningTable::factory()->create(['company_id' => $company->id]);
        $product = Product::factory()->create(['company_id' => $company->id, 'sale_price' => '10', 'stock_quantity' => '10']);

        return [$company, $table, $product, ['service_context' => $table->service_context, 'client_uuid' => (string) Str::uuid(),
            'tracking_key' => str_repeat('a', 64), 'items' => [['product_id' => $product->id, 'quantity' => 2, 'notes' => 'Sem sal']]]];
    }

    public function test_pending_receipt_is_private_without_charging_or_moving_stock(): void
    {
        [$company, $table, $product, $data] = $this->menu();
        $this->postJson('/m/'.$table->qr_token, $data)->assertCreated()->assertJsonPath('receipt.status', 'pending')->assertJsonPath('receipt.total', '20.00');
        $this->assertSame('0.00', TableOrder::sole()->subtotal());
        foreach (['table_order_items', 'cash_movements', 'stock_movements'] as $name) {
            $this->assertDatabaseCount($name, 0);
        }
        $this->assertSame('10.000', $product->fresh()->stock_quantity);
        $url = '/m/'.$table->qr_token.'/envios/'.$data['client_uuid'];
        $this->getJson($url)->assertNotFound();
        $this->withHeader('X-Menu-Receipt-Key', $data['tracking_key'])->getJson($url)->assertOk()
            ->assertJsonPath('receipt.items.0.product_name', $product->name)->assertJsonMissingPath('receipt.table_order_id')->assertJsonMissingPath('receipt.decided_by');
        $this->assertStringNotContainsString($data['tracking_key'], DB::table('audit_logs')->get()->toJson());
    }

    public function test_replay_survives_price_and_context_change_but_conflict_is_refused(): void
    {
        [$company, $table, $product, $data] = $this->menu();
        $this->postJson('/m/'.$table->qr_token, $data)->assertCreated();
        $product->forceFill(['sale_price' => '99', 'active' => false])->save();
        $table->forceFill(['service_context' => Str::random(64)])->save();
        $this->postJson('/m/'.$table->qr_token, $data)->assertOk()->assertJsonPath('receipt.total', '20.00');
        $data['items'][0]['quantity'] = 3;
        $this->postJson('/m/'.$table->qr_token, $data)->assertConflict();
        $this->assertDatabaseCount('menu_order_receipts', 1);
        $this->assertDatabaseCount('table_orders', 1);
    }

    public function test_confirmation_copies_snapshot_once_and_rejection_does_not_charge(): void
    {
        [$company, $table, $product, $data] = $this->menu();
        $this->postJson('/m/'.$table->qr_token, $data)->assertCreated();
        $receipt = MenuOrderReceipt::sole();
        $admin = $this->productionAdminOf($company);
        $product->forceFill(['sale_price' => '99'])->save();
        $this->actingAs($admin)->postJson('/mesas/recebimentos/'.$receipt->id.'/confirmar')->assertOk();
        $this->postJson('/mesas/recebimentos/'.$receipt->id.'/confirmar')->assertOk();
        $this->assertDatabaseHas('audit_logs', ['auditable_type' => 'MenuOrderReceipt', 'auditable_id' => $receipt->id, 'event' => 'updated', 'user_id' => $admin->id]);
        $this->assertSame('20.00', TableOrder::sole()->subtotal());
        $this->assertDatabaseCount('table_order_items', 1);
        $this->assertDatabaseHas('table_order_items', ['unit_price' => '10', 'quantity' => '2']);
        $this->postJson('/mesas/recebimentos/'.$receipt->id.'/rejeitar', ['reason' => 'Tarde'])->assertUnprocessable();
        $data['client_uuid'] = (string) Str::uuid();
        $this->postJson('/m/'.$table->qr_token, $data)->assertCreated();
        $second = MenuOrderReceipt::orderByDesc('id')->first();
        $this->actingAs($admin)->postJson('/mesas/recebimentos/'.$second->id.'/rejeitar', ['reason' => 'Indisponível'])->assertOk();
        $this->assertSame('20.00', TableOrder::sole()->subtotal());
        $this->assertDatabaseCount('stock_movements', 0);
    }

    public function test_stale_page_and_pending_limit_refuse_without_partial_effects(): void
    {
        [$company, $table, $product, $data] = $this->menu();
        (new RestaurantOperationSettings)->forceFill(['company_id' => $company->id, 'max_pending_orders' => 1])->save();
        $this->postJson('/m/'.$table->qr_token, $data)->assertCreated();
        $data['client_uuid'] = (string) Str::uuid();
        $this->postJson('/m/'.$table->qr_token, $data)->assertUnprocessable()->assertJsonValidationErrors('items');
        $table->forceFill(['service_context' => Str::random(64)])->save();
        $this->postJson('/m/'.$table->qr_token, $data)->assertUnprocessable()->assertJsonValidationErrors('service_context');
        $this->assertDatabaseCount('menu_order_receipts', 1);
        $this->assertDatabaseCount('table_orders', 1);
    }

    public function test_cross_company_decision_and_insufficient_permission_are_refused(): void
    {
        [$company, $table, $product, $data] = $this->menu();
        $this->postJson('/m/'.$table->qr_token, $data)->assertCreated();
        $receipt = MenuOrderReceipt::sole();
        $this->actingAs($this->userWith(['tables.operate'], $company))->postJson('/mesas/recebimentos/'.$receipt->id.'/confirmar')->assertForbidden();
        $this->actingAs($this->productionAdminOf())->postJson('/mesas/recebimentos/'.$receipt->id.'/confirmar')->assertNotFound();
        $this->assertSame('pending', $receipt->fresh()->status);
        $this->assertDatabaseCount('table_order_items', 0);
    }

    public function test_foreign_logged_in_employee_is_anonymous_in_public_menu(): void
    {
        [$company, $table, $product, $data] = $this->menu();
        $this->actingAs($this->productionAdminOf())->postJson('/m/'.$table->qr_token, $data)->assertCreated();
        $this->assertDatabaseHas('menu_order_receipts', ['company_id' => $company->id]);
        $this->assertDatabaseHas('audit_logs', ['auditable_type' => 'MenuOrderReceipt', 'company_id' => $company->id, 'user_id' => null]);
    }

    public function test_forged_price_and_foreign_product_cannot_bypass_validation(): void
    {
        [$company, $table, $product, $data] = $this->menu();
        $data['items'][0]['unit_price'] = '0.01';
        $this->postJson('/m/'.$table->qr_token, $data)->assertCreated()->assertJsonPath('receipt.total', '20.00');
        $data['client_uuid'] = (string) Str::uuid();
        $data['items'][0]['product_id'] = Product::factory()->create()->id;
        $this->postJson('/m/'.$table->qr_token, $data)->assertUnprocessable()->assertJsonValidationErrors('items.0.product_id');
        $this->assertDatabaseCount('menu_order_receipts', 1);
    }
}
