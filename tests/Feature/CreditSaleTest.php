<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Bill;
use App\Models\CashSession;
use App\Models\Customer;
use App\Models\DiningTable;
use App\Models\Product;
use App\Models\Sale;
use App\Models\TableOrder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class CreditSaleTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Product $product;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->adminOf();
        $this->product = Product::factory()->create(['company_id' => $this->admin->company_id, 'sale_price' => '10', 'stock_quantity' => '100']);
        $this->customer = Customer::factory()->create([
            'company_id' => $this->admin->company_id,
            'name' => 'João Fiado',
            'allow_credit' => true,
            'credit_limit' => '100',
            'credit_days' => 15,
        ]);
        $this->openCash($this->admin, '50');
    }

    private function sale(array $overrides = [], string $quantity = '2')
    {
        return $this->actingAs($this->admin)->post('/vendas', [
            'client_uuid' => (string) Str::uuid(),
            'customer_id' => $this->customer->id,
            'items' => [['product_id' => $this->product->id, 'quantity' => $quantity, 'expected_price' => '10.00']],
            'payments' => [['method' => 'fiado', 'amount' => (string) ((int) $quantity * 10)]],
            ...$overrides,
        ]);
    }

    public function test_fiado_gera_conta_a_receber_com_prazo_do_cliente(): void
    {
        $this->sale()->assertSessionHasNoErrors();

        $sale = Sale::sole();
        $bill = Bill::sole();

        $this->assertSame($this->customer->id, $sale->customer_id);
        $this->assertSame(Bill::RECEIVABLE, $bill->type);
        $this->assertSame('20.00', $bill->amount);
        $this->assertSame('Vendas a prazo', $bill->category);
        $this->assertSame('João Fiado', $bill->counterparty);
        $this->assertSame($sale->id, $bill->sale_id);
        $this->assertSame(today()->addDays(15)->format('Y-m-d'), $bill->due_date->format('Y-m-d'));
        $this->assertSame('98.000', $this->product->fresh()->stock_quantity);
    }

    public function test_fiado_exige_cliente_autorizado(): void
    {
        $this->sale(['customer_id' => null])->assertSessionHasErrors('payments');

        $other = Customer::factory()->create(['company_id' => $this->admin->company_id, 'allow_credit' => false]);

        $this->sale(['customer_id' => $other->id])->assertSessionHasErrors('payments');
        $this->assertSame(0, Sale::count());
    }

    public function test_fiado_exige_permissao(): void
    {
        $seller = $this->userWith(['sales.create', 'cash.operate'], $this->admin->company);
        $this->openCash($seller);

        $this->actingAs($seller)->post('/vendas', [
            'client_uuid' => (string) Str::uuid(),
            'customer_id' => $this->customer->id,
            'items' => [['product_id' => $this->product->id, 'quantity' => '1', 'expected_price' => '10.00']],
            'payments' => [['method' => 'fiado', 'amount' => '10']],
        ])->assertSessionHasErrors('payments');
    }

    public function test_limite_considera_o_que_ja_deve(): void
    {
        $this->sale([], '8')->assertSessionHasNoErrors();
        $this->sale([], '3')->assertSessionHasErrors('payments');
        $this->sale([], '2')->assertSessionHasNoErrors();

        $this->assertSame('100.00', $this->customer->openCredit());
    }

    public function test_cliente_sem_limite_definido_nao_trava(): void
    {
        $this->customer->update(['credit_limit' => null]);

        $this->sale([], '50')->assertSessionHasNoErrors();
    }

    public function test_quitar_libera_o_limite(): void
    {
        $this->sale([], '10');

        $this->post('/financeiro/contas/'.Bill::sole()->id.'/baixa', [
            'paid_at' => today()->format('Y-m-d'),
            'paid_amount' => '100',
            'payment_method' => 'pix',
        ])->assertSessionHasNoErrors();

        $this->assertSame('0.00', $this->customer->openCredit());
        $this->sale([], '1')->assertSessionHasNoErrors();
    }

    public function test_pagamento_misto_parte_fiado_parte_dinheiro(): void
    {
        $this->sale(['payments' => [['method' => 'cash', 'amount' => '15'], ['method' => 'fiado', 'amount' => '10']]])
            ->assertSessionHasNoErrors();

        $sale = Sale::sole();
        $this->assertSame('5.00', $sale->change);
        $this->assertSame('10.00', Bill::sole()->amount);
        $this->assertSame('10.00', $sale->payments()->where('method', 'cash')->sole()->amount);
    }

    public function test_fiado_nao_gera_troco(): void
    {
        $this->sale(['payments' => [['method' => 'fiado', 'amount' => '30']]])->assertSessionHasErrors('payments');
    }

    public function test_cancelar_venda_cancela_a_conta_em_aberto(): void
    {
        $this->sale();

        $this->post('/vendas/'.Sale::sole()->id.'/cancelar', ['reason' => 'Erro'])->assertSessionHas('success');

        $this->assertSame(Bill::CANCELLED, Bill::sole()->status);
        $this->assertSame('0.00', $this->customer->openCredit());
    }

    public function test_nao_cancela_venda_com_fiado_ja_quitado(): void
    {
        $this->sale();
        Bill::sole()->forceFill(['status' => Bill::PAID, 'paid_amount' => '20', 'paid_at' => today()])->save();

        $this->post('/vendas/'.Sale::sole()->id.'/cancelar', ['reason' => 'Erro'])->assertSessionHasErrors('sale');

        $this->assertFalse(Sale::sole()->isCancelled());
    }

    public function test_fiado_nao_conta_duas_vezes_no_fluxo_do_dia(): void
    {
        $this->sale();
        $this->sale(['payments' => [['method' => 'pix', 'amount' => '20']]]);

        $this->get('/caixa/fluxo')->assertInertia(
            fn (Assert $page) => $page->where('gross', '20.00')->where('creditSales', '20.00')->where('balance', '20.00')
        );

        $this->post('/financeiro/contas/'.Bill::sole()->id.'/baixa', [
            'paid_at' => today()->format('Y-m-d'),
            'paid_amount' => '20',
            'payment_method' => 'pix',
        ]);

        $this->get('/caixa/fluxo')->assertInertia(fn (Assert $page) => $page->where('balance', '40.00'));
    }

    public function test_cancelamento_posterior_de_fiado_nao_tira_do_resultado(): void
    {
        $this->travelTo(now()->setTime(12, 0));
        $this->sale();

        $this->travel(1)->days();

        $this->post('/vendas/'.Sale::sole()->id.'/cancelar', ['reason' => 'x'])->assertSessionHasNoErrors();

        $this->get('/caixa/fluxo')->assertInertia(fn (Assert $page) => $page->where('cancellations', '0.00')->where('net', '0.00'));
    }

    public function test_fiado_nao_entra_no_dinheiro_esperado(): void
    {
        $this->sale();

        $session = CashSession::query()->where('open_user_id', $this->admin->id)->sole();

        $this->assertSame('50.00', $session->calculateSummary()['expected_cash']);
    }

    public function test_fiado_na_mesa(): void
    {
        $table = DiningTable::factory()->create(['company_id' => $this->admin->company_id]);

        $this->actingAs($this->admin)->post("/mesas/{$table->id}/abrir", ['people' => 1]);
        $order = TableOrder::sole();
        $this->post("/mesas/comandas/{$order->id}/itens", [
            'client_uuid' => (string) Str::uuid(),
            'items' => [['product_id' => $this->product->id, 'quantity' => '2']],
        ]);

        $this->post("/mesas/comandas/{$order->id}/fechar", [
            'client_uuid' => (string) Str::uuid(),
            'expected_subtotal' => '20.00',
            'charge_service' => false,
            'customer_id' => $this->customer->id,
            'payments' => [['method' => 'fiado', 'amount' => '20']],
        ])->assertSessionHasNoErrors();

        $this->assertSame('20.00', Bill::sole()->amount);
    }

    public function test_cliente_de_outra_empresa_nao_pode_ser_usado(): void
    {
        $foreign = Customer::factory()->create(['allow_credit' => true]);

        $this->sale(['customer_id' => $foreign->id])->assertSessionHasErrors('customer_id');
    }

    public function test_auditoria_mostra_cliente_e_fiado(): void
    {
        $this->sale();

        $log = AuditLog::where('event', 'sale')->sole();

        $this->assertSame('João Fiado', $log->new_values['customer']);
        $this->assertSame(['Fiado: R$ 20,00'], $log->new_values['payments']);
    }
}
