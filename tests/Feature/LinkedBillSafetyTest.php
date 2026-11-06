<?php

namespace Tests\Feature;

use App\Models\Bill;
use App\Models\CashSession;
use App\Models\Customer;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class LinkedBillSafetyTest extends TestCase
{
    use RefreshDatabase;

    private Product $product;

    private CashSession $session;

    public static function origins(): array
    {
        return ['sale' => ['sale'], 'stock entry' => ['stock_entry']];
    }

    private function linkedBill(string $origin): Bill
    {
        $admin = $this->adminOf();
        $this->actingAs($admin);
        $this->openCash($admin, '100');
        $this->session = CashSession::where('open_user_id', $admin->id)->sole();
        $this->product = Product::factory()->create(['company_id' => $admin->company_id, 'sale_price' => '80', 'stock_quantity' => '10', 'track_stock' => true]);
        if ($origin === 'sale') {
            $customer = Customer::factory()->create(['company_id' => $admin->company_id, 'allow_credit' => true, 'credit_limit' => '1000']);
            $this->post('/vendas', [
                'client_uuid' => (string) Str::uuid(), 'customer_id' => $customer->id,
                'items' => [['product_id' => $this->product->id, 'quantity' => '1', 'expected_price' => '80.00']],
                'payments' => [['method' => 'fiado', 'amount' => '80']],
            ])->assertSessionHasNoErrors();
        } else {
            $this->post('/entradas', [
                'entry_date' => today()->format('Y-m-d'),
                'items' => [['product_id' => $this->product->id, 'quantity' => '1', 'unit_cost' => '80']],
                'generate_bill' => true, 'bill_due_date' => today()->format('Y-m-d'), 'bill_installments' => 1,
            ])->assertSessionHasNoErrors();
        }

        return Bill::sole();
    }

    private function effects(): array
    {
        $tables = ['sales', 'bills', 'stock_entries', 'stock_movements', 'sale_payments', 'cash_movements', 'audit_logs'];

        return [
            'data' => array_map(fn ($table) => DB::table($table)->orderBy('id')->get()->toJson(), $tables),
            'stock' => $this->product->fresh()->stock_quantity,
            'cash' => $this->session->calculateSummary()['expected_cash'],
        ];
    }

    #[DataProvider('origins')]
    public function test_partial_receipt_must_be_reversed_before_cancelling_origin(string $origin): void
    {
        $bill = $this->linkedBill($origin);
        $url = $origin === 'sale' ? '/vendas/'.$bill->sale_id.'/cancelar' : '/entradas/'.$bill->stock_entry_id.'/cancelar';
        $this->post("/financeiro/contas/{$bill->id}/baixa", ['paid_at' => today()->format('Y-m-d'), 'paid_amount' => '1', 'payment_method' => 'cash'])->assertSessionHasNoErrors();
        $before = $this->effects();

        $this->post($url, ['reason' => 'Ainda existe baixa'])->assertSessionHasErrors($origin === 'sale' ? 'sale' : 'stock');
        $this->assertSame($before, $this->effects());
        $this->assertSame('79.00', $bill->fresh()->balance());
        $selected = $bill->settlements()->sole();
        $this->post("/financeiro/contas/{$bill->id}/estorno", ['reason' => 'Corrigir origem', 'settlement_id' => $selected->id])->assertSessionHasNoErrors();
        $this->post($url, ['reason' => 'Origem após estorno'])->assertSessionHasNoErrors();

        $this->assertSame(Bill::CANCELLED, $bill->fresh()->status);
        $this->assertSame('10.000', $this->product->fresh()->stock_quantity);
        $this->assertSame('100.00', $this->session->calculateSummary()['expected_cash']);
        $this->assertDatabaseCount('cash_movements', 2);
        $this->assertDatabaseCount('stock_movements', 2);
        $this->assertNotNull($selected->fresh()->reversed_at);
    }

    #[DataProvider('origins')]
    public function test_linked_bill_cannot_be_edited_directly(string $origin): void
    {
        $bill = $this->linkedBill($origin);
        $before = $this->effects();

        $this->get("/financeiro/contas/{$bill->id}/editar")->assertForbidden();
        $this->put("/financeiro/contas/{$bill->id}", [
            'description' => 'Alteração indevida', 'category' => $bill->category,
            'amount' => '5', 'due_date' => today()->format('Y-m-d'),
        ])->assertForbidden();

        $this->assertSame($before, $this->effects());
    }

    #[DataProvider('origins')]
    public function test_linked_bill_cannot_be_cancelled_directly(string $origin): void
    {
        $bill = $this->linkedBill($origin);
        $before = $this->effects();

        $this->post("/financeiro/contas/{$bill->id}/cancelar", ['reason' => 'Tentativa direta'])->assertSessionHasErrors('reason');

        $this->assertSame($before, $this->effects());
    }

    #[DataProvider('origins')]
    public function test_origin_cancellation_remains_valid_and_reverses_stock_once(string $origin): void
    {
        $bill = $this->linkedBill($origin);
        $url = $origin === 'sale' ? '/vendas/'.$bill->sale_id.'/cancelar' : '/entradas/'.$bill->stock_entry_id.'/cancelar';

        $this->post($url, ['reason' => 'Origem incorreta'])->assertSessionHasNoErrors()->assertSessionHas('success');

        $this->assertSame(Bill::CANCELLED, $bill->fresh()->status);
        $this->assertSame('10.000', $this->product->fresh()->stock_quantity);
        $this->assertSame('100.00', $this->session->calculateSummary()['expected_cash']);
        $this->assertDatabaseCount('cash_movements', 0);
        $this->assertDatabaseCount('stock_movements', 2);
        $this->post($url, ['reason' => 'Repetição'])->assertSessionHasErrors($origin === 'sale' ? 'sale' : 'stock');
        $this->assertDatabaseCount('stock_movements', 2);
    }
}
