<?php

namespace Tests\Feature;

use App\Models\Bill;
use App\Models\BillSettlement;
use App\Models\Customer;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class BillSettlementReportTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Bill $bill;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->setDate(2026, 11, 6)->setTime(12, 0));
        $this->admin = $this->adminOf();
        $this->actingAs($this->admin);
        $customer = Customer::factory()->create(['company_id' => $this->admin->company_id]);
        $this->bill = Bill::factory()->create(['company_id' => $this->admin->company_id, 'customer_id' => $customer->id, 'type' => Bill::RECEIVABLE, 'amount' => '80', 'due_date' => today()->subDay()]);
    }

    private function pay(string $amount, array $extra = []): void
    {
        $this->post("/financeiro/contas/{$this->bill->id}/baixa", [
            'paid_at' => today()->format('Y-m-d'), 'paid_amount' => $amount, 'payment_method' => 'pix', ...$extra,
        ])->assertSessionHasNoErrors();
    }

    public function test_partial_receipt_updates_balance_summary_flow_and_debtors(): void
    {
        $this->pay('1');

        $this->get('/financeiro/receber')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('summary.open', fn ($value) => (string) $value === '79' || (string) $value === '79.00')
            ->where('summary.paid_month', fn ($value) => (string) $value === '1' || (string) $value === '1.00')
            ->where('bills.data.0.balance', '79.00'));
        $this->get('/caixa/fluxo')->assertOk()->assertInertia(fn (Assert $page) => $page->where('billsReceived', '1.00')->where('balance', '1.00'));
        $this->get('/relatorios/financeiro')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('open.receivable', '79.00')->where('open.receivable_overdue', '79.00')->where('debtors.0.total', '79.00')->where('statement.other_income', '1.00'));
    }

    public function test_reversal_is_negative_on_its_own_date_and_does_not_erase_original_receipt(): void
    {
        $this->pay('1');
        $this->travel(1)->days();
        $this->pay('30');
        $selected = BillSettlement::where('amount', '30')->sole();
        $this->travel(1)->days();
        $this->pay('49');
        $this->travel(1)->days();
        $this->post("/financeiro/contas/{$this->bill->id}/estorno", ['reason' => 'Correção', 'settlement_id' => $selected->id])->assertSessionHasNoErrors();

        foreach (['2026-11-06' => '1.00', '2026-11-07' => '30.00', '2026-11-08' => '49.00', '2026-11-09' => '-30.00'] as $day => $amount) {
            $this->get('/caixa/fluxo?date='.$day)->assertOk()->assertInertia(fn (Assert $page) => $page->where('billsReceived', $amount));
        }
        $this->get('/relatorios/financeiro')->assertOk()->assertInertia(fn (Assert $page) => $page->where('statement.other_income', '50.00')->where('open.receivable', '30.00'));
    }

    public function test_partial_expense_is_counted_without_waiting_for_full_settlement(): void
    {
        $this->bill->forceFill(['type' => Bill::PAYABLE, 'category' => 'Aluguel'])->save();
        $this->pay('10');

        $this->get('/relatorios/financeiro')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('statement.expenses', '10.00')->where('statement.result', '-10.00')->where('open.payable', '70.00'));
    }

    public function test_credit_sale_discount_is_explicit_in_result_and_not_cash_income(): void
    {
        $this->bill->delete();
        $customer = Customer::factory()->create(['company_id' => $this->admin->company_id, 'allow_credit' => true, 'credit_limit' => '1000']);
        $product = Product::factory()->create(['company_id' => $this->admin->company_id, 'sale_price' => '80', 'cost_price' => '10']);
        $this->openCash($this->admin, '0');
        $this->post('/vendas', ['client_uuid' => (string) Str::uuid(), 'customer_id' => $customer->id,
            'items' => [['product_id' => $product->id, 'quantity' => '1', 'expected_price' => '80.00']],
            'payments' => [['method' => 'fiado', 'amount' => '80']],
        ])->assertSessionHasNoErrors();
        $this->bill = Bill::sole();
        $this->pay('70', ['discount_amount' => '10', 'discount_reason' => 'Acordo']);

        $this->get('/relatorios/financeiro')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('statement.revenue', '80.00')->where('statement.other_income', '0.00')
            ->where('statement.credit_discounts', '10.00')->where('statement.result', '60.00')->where('open.receivable', '0.00'));
        $this->get('/caixa/fluxo')->assertOk()->assertInertia(fn (Assert $page) => $page->where('billsReceived', '70.00')->where('balance', '70.00'));
    }

    public function test_reports_and_settlement_selection_are_isolated_between_companies(): void
    {
        $this->pay('1');
        $other = $this->adminOf();
        $foreign = Bill::factory()->create(['company_id' => $other->company_id, 'type' => Bill::RECEIVABLE, 'amount' => '200']);
        $this->actingAs($other)->post("/financeiro/contas/{$foreign->id}/baixa", ['paid_at' => today()->format('Y-m-d'), 'paid_amount' => '100', 'payment_method' => 'pix'])->assertSessionHasNoErrors();
        $foreignSettlement = BillSettlement::where('bill_id', $foreign->id)->sole();
        $this->actingAs($this->admin);

        $this->get('/caixa/fluxo')->assertOk()->assertInertia(fn (Assert $page) => $page->where('billsReceived', '1.00'));
        $this->post("/financeiro/contas/{$this->bill->id}/estorno", ['reason' => 'Outra baixa', 'settlement_id' => $foreignSettlement->id])->assertSessionHasErrors('settlement_id');
        $this->get("/financeiro/contas/{$foreign->id}")->assertNotFound();

        $this->assertNull($foreignSettlement->fresh()->reversed_at);
    }
}
