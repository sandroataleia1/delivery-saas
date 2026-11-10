<?php

namespace Tests\Feature;

use App\Actions\CancelTableOrder;
use App\Actions\OpenTable;
use App\Actions\TransferTable;
use App\Models\DiningTable;
use App\Models\MenuOrderReceipt;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class TableContextTest extends TestCase
{
    use RefreshDatabase;

    public function test_open_preserves_context_transfer_rotates_both_and_cancel_rotates_current_table(): void
    {
        $admin = $this->adminOf();
        $this->actingAs($admin);
        $table = DiningTable::factory()->create(['company_id' => $admin->company_id]);
        $target = DiningTable::factory()->create(['company_id' => $admin->company_id]);
        $before = $table->service_context;
        $other = $target->service_context;
        $this->assertSame(64, strlen($before ?? ''));
        $order = app(OpenTable::class)->handle($table, 1, $admin);
        $this->assertSame($before, $table->fresh()->service_context);
        app(TransferTable::class)->handle($order, $target);
        $this->assertNotSame($before, $table->fresh()->service_context);
        $this->assertNotSame($other, $target->fresh()->service_context);
        $current = $target->fresh()->service_context;
        $this->assertSame($target->id, $order->fresh()->dining_table_id);
        app(CancelTableOrder::class)->handle($order->fresh(), 'Atendimento encerrado');
        $this->assertNotSame($current, $target->fresh()->service_context);
    }

    public function test_pending_receipt_prevents_cancelling_the_attendance_without_losing_data(): void
    {
        $admin = $this->adminOf();
        $this->actingAs($admin);
        $table = DiningTable::factory()->create(['company_id' => $admin->company_id]);
        $order = app(OpenTable::class)->handle($table, 1, $admin);
        (new MenuOrderReceipt)->forceFill(['company_id' => $admin->company_id, 'dining_table_id' => $table->id,
            'table_order_id' => $order->id, 'service_context' => $table->service_context ?? 'old',
            'client_uuid' => (string) Str::uuid(), 'content_hash' => str_repeat('a', 64),
            'tracking_key_hash' => str_repeat('b', 64), 'units' => 1, 'total' => '10', 'policy_snapshot' => []])->save();
        try {
            app(CancelTableOrder::class)->handle($order, 'Não pode ocultar pendência');
            $this->fail('Cancelamento deve recusar recebimento pendente.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('order', $exception->errors());
        }
        $this->assertTrue($order->fresh()->isOpen());
        $this->assertDatabaseHas('menu_order_receipts', ['table_order_id' => $order->id, 'status' => 'pending']);
        $this->assertDatabaseCount('sales', 0);
        $this->assertDatabaseCount('stock_movements', 0);
    }
}
