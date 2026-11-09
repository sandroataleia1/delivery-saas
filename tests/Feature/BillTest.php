<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Bill;
use App\Models\CashMovement;
use App\Models\CashSession;
use App\Models\Product;
use App\Models\StockEntry;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class BillTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->adminOf();
    }

    private function payload(array $overrides = []): array
    {
        return [
            'description' => 'Aluguel',
            'category' => 'Aluguel',
            'amount' => '1000',
            'due_date' => today()->addDays(10)->format('Y-m-d'),
            ...$overrides,
        ];
    }

    private function bill(array $attributes = []): Bill
    {
        return Bill::factory()->create(['company_id' => $this->admin->company_id, ...$attributes]);
    }

    private function settlement(array $overrides = []): array
    {
        return [
            'paid_at' => today()->format('Y-m-d'),
            'paid_amount' => '100',
            'payment_method' => 'pix',
            ...$overrides,
        ];
    }

    private function openSession(string $amount = '500'): CashSession
    {
        $this->openCash($this->admin, $amount);

        return CashSession::query()->where('open_user_id', $this->admin->id)->sole();
    }

    public function test_cria_conta_a_pagar_e_a_receber(): void
    {
        $this->actingAs($this->admin);

        $this->post('/financeiro/pagar', $this->payload())->assertRedirect(route('bills.index', 'pagar'));
        $this->post('/financeiro/receber', $this->payload([
            'description' => 'Encomenda da escola',
            'category' => 'Eventos e encomendas',
            'counterparty' => 'Escola Modelo',
        ]))->assertRedirect(route('bills.index', 'receber'));

        $this->assertSame(Bill::PAYABLE, Bill::where('description', 'Aluguel')->sole()->type);
        $this->assertSame(Bill::RECEIVABLE, Bill::where('description', 'Encomenda da escola')->sole()->type);
        $this->assertSame($this->admin->name, Bill::first()->user_name);
    }

    public function test_tipo_invalido_nao_existe(): void
    {
        $this->actingAs($this->admin)->get('/financeiro/outra')->assertNotFound();
    }

    public function test_categoria_precisa_ser_do_tipo_da_conta(): void
    {
        $this->actingAs($this->admin)->post('/financeiro/pagar', $this->payload(['category' => 'Vendas a prazo']))
            ->assertSessionHasErrors('category');
    }

    public function test_parcelas_somam_o_total_e_vencem_mes_a_mes(): void
    {
        $this->actingAs($this->admin)->post('/financeiro/pagar', $this->payload([
            'amount' => '100',
            'installments' => 3,
            'due_date' => '2027-01-31',
        ]))->assertSessionHas('success', '3 parcelas criadas.');

        $bills = Bill::orderBy('installment_number')->get();

        $this->assertSame(['33.34', '33.33', '33.33'], $bills->pluck('amount')->all());
        $this->assertSame(['2027-01-31', '2027-02-28', '2027-03-31'], $bills->map(fn ($bill) => $bill->due_date->format('Y-m-d'))->all());
        $this->assertSame('Aluguel (2/3)', $bills[1]->fullDescription());
        $this->assertSame(1, $bills->pluck('installment_group')->unique()->count());
    }

    public function test_baixa_sem_dinheiro_do_caixa_nao_mexe_na_gaveta(): void
    {
        $bill = $this->bill();

        $this->actingAs($this->admin)->post("/financeiro/contas/{$bill->id}/baixa", $this->settlement())->assertSessionHas('success');

        $bill->refresh();
        $this->assertTrue($bill->isPaid());
        $this->assertSame('100.00', $bill->paid_amount);
        $this->assertSame('pix', $bill->payment_method);
        $this->assertSame($this->admin->name, $bill->settled_by);
        $this->assertSame(0, CashMovement::count());
    }

    public function test_nao_presume_juros_ao_receber_acima_do_saldo(): void
    {
        $bill = $this->bill(['amount' => '100']);

        $this->actingAs($this->admin)->post("/financeiro/contas/{$bill->id}/baixa", $this->settlement(['paid_amount' => '103.50']))->assertSessionHasErrors('paid_amount');

        $this->assertSame('100.00', $bill->fresh()->amount);
        $this->assertNull($bill->fresh()->paid_amount);
        $this->assertTrue($bill->fresh()->isOpen());
        $this->assertDatabaseCount('bill_settlements', 0);
    }

    public function test_conta_paga_em_dinheiro_sai_do_caixa_aberto(): void
    {
        $session = $this->openSession('500');
        $bill = $this->bill();

        $this->actingAs($this->admin)->post("/financeiro/contas/{$bill->id}/baixa", $this->settlement(['payment_method' => 'cash']))
            ->assertSessionHasNoErrors();

        $movement = CashMovement::sole();
        $this->assertSame(CashMovement::BILL_PAYMENT, $movement->type);
        $this->assertSame($session->id, $movement->cash_session_id);
        $this->assertSame($movement->id, $bill->fresh()->cash_movement_id);
        $this->assertSame('400.00', $session->calculateSummary()['expected_cash']);
    }

    public function test_conta_recebida_em_dinheiro_entra_no_caixa(): void
    {
        $session = $this->openSession('500');
        $bill = $this->bill(['type' => Bill::RECEIVABLE]);

        $this->actingAs($this->admin)->post("/financeiro/contas/{$bill->id}/baixa", $this->settlement(['payment_method' => 'cash']));

        $this->assertSame('600.00', $session->calculateSummary()['expected_cash']);
    }

    public function test_dinheiro_exige_caixa_aberto_saldo_e_data_de_hoje(): void
    {
        $bill = $this->bill();

        $this->actingAs($this->admin);

        $this->post("/financeiro/contas/{$bill->id}/baixa", $this->settlement(['payment_method' => 'cash']))
            ->assertSessionHasErrors('payment_method');

        $this->openSession('50');

        $this->post("/financeiro/contas/{$bill->id}/baixa", $this->settlement(['payment_method' => 'cash']))
            ->assertSessionHasErrors('paid_amount');

        $this->post("/financeiro/contas/{$bill->id}/baixa", $this->settlement([
            'payment_method' => 'cash',
            'paid_amount' => '10',
            'paid_at' => today()->subDay()->format('Y-m-d'),
        ]))->assertSessionHasErrors('paid_at');

        $this->assertTrue($bill->fresh()->isOpen());
        $this->assertSame(0, CashMovement::count());
    }

    public function test_nao_baixa_duas_vezes(): void
    {
        $bill = $this->bill();

        $this->actingAs($this->admin);
        $this->post("/financeiro/contas/{$bill->id}/baixa", $this->settlement());
        $this->post("/financeiro/contas/{$bill->id}/baixa", $this->settlement())->assertSessionHasErrors('bill');
    }

    public function test_data_de_baixa_nao_pode_ser_futura(): void
    {
        $bill = $this->bill();

        $this->actingAs($this->admin)->post("/financeiro/contas/{$bill->id}/baixa", $this->settlement([
            'paid_at' => today()->addDay()->format('Y-m-d'),
        ]))->assertSessionHasErrors('paid_at');
    }

    public function test_estorno_de_pagamento_em_dinheiro_devolve_ao_caixa_com_novo_lancamento(): void
    {
        $session = $this->openSession('500');
        $bill = $this->bill();

        $this->actingAs($this->admin);
        $this->post("/financeiro/contas/{$bill->id}/baixa", $this->settlement(['payment_method' => 'cash']));
        $this->post("/financeiro/contas/{$bill->id}/estorno", ['reason' => 'Paguei errado'])->assertSessionHas('success');

        $bill->refresh();
        $this->assertTrue($bill->isOpen());
        $this->assertNull($bill->paid_at);
        $this->assertNull($bill->cash_movement_id);
        $this->assertSame(2, CashMovement::count());
        $this->assertSame('500.00', $session->calculateSummary()['expected_cash']);
        $this->assertDatabaseHas('audit_logs', ['event' => 'bill_reopened', 'user_id' => $this->admin->id]);
    }

    public function test_estorno_em_dinheiro_com_caixa_fechado_pede_caixa_aberto(): void
    {
        $session = $this->openSession('500');
        $bill = $this->bill();

        $this->actingAs($this->admin);
        $this->post("/financeiro/contas/{$bill->id}/baixa", $this->settlement(['payment_method' => 'cash']));
        $this->post("/caixa/{$session->id}/fechar", ['counted_cash' => '400']);

        $this->post("/financeiro/contas/{$bill->id}/estorno", ['reason' => 'x'])->assertSessionHasErrors('reason');
        $this->assertTrue($bill->fresh()->isPaid());
        $this->assertSame('400.00', $session->fresh()->expected_cash);
    }

    public function test_cancelar_exige_motivo_e_conta_em_aberto(): void
    {
        $bill = $this->bill();

        $this->actingAs($this->admin);
        $this->post("/financeiro/contas/{$bill->id}/cancelar", [])->assertSessionHasErrors('reason');
        $this->post("/financeiro/contas/{$bill->id}/cancelar", ['reason' => 'Duplicada'])->assertSessionHas('success');

        $this->assertSame('Duplicada', $bill->fresh()->cancel_reason);

        $this->post("/financeiro/contas/{$bill->id}/cancelar", ['reason' => 'x'])->assertSessionHasErrors('reason');
    }

    public function test_so_edita_conta_em_aberto(): void
    {
        $bill = $this->bill(['status' => Bill::PAID]);

        $this->actingAs($this->admin);
        $this->get("/financeiro/contas/{$bill->id}/editar")->assertForbidden();
        $this->put("/financeiro/contas/{$bill->id}", $this->payload())->assertForbidden();
    }

    public function test_formulario_nao_troca_situacao_nem_valor_pago(): void
    {
        $bill = $this->bill();

        $this->actingAs($this->admin)->put("/financeiro/contas/{$bill->id}", $this->payload([
            'status' => Bill::PAID,
            'paid_amount' => '1',
            'category' => 'Outros',
        ]))->assertSessionHasNoErrors();

        $this->assertTrue($bill->fresh()->isOpen());
        $this->assertNull($bill->fresh()->paid_amount);
    }

    public function test_vencidas_e_totais(): void
    {
        $this->bill(['amount' => '10', 'due_date' => today()->subDay()]);
        $this->bill(['amount' => '20', 'due_date' => today()]);
        $this->bill(['amount' => '40', 'due_date' => today()->addDays(30)]);
        $this->bill(['amount' => '80', 'type' => Bill::RECEIVABLE]);
        $this->bill(['amount' => '160', 'status' => Bill::PAID, 'paid_amount' => '160', 'paid_at' => today()]);

        $this->actingAs($this->admin);

        $this->get('/financeiro/pagar')->assertInertia(
            fn (Assert $page) => $page->has('bills.data', 4)
                ->where('bills.data.0.status', 'overdue')
                ->where('summary.open', fn ($value) => (float) $value === 70.0)
                ->where('summary.overdue', fn ($value) => (float) $value === 10.0)
                ->where('summary.next_days', fn ($value) => (float) $value === 20.0)
                ->where('summary.paid_month', fn ($value) => (float) $value === 160.0)
        );

        $this->get('/financeiro/pagar?status=overdue')->assertInertia(fn (Assert $page) => $page->has('bills.data', 1));
        $this->get('/financeiro/pagar?status=open')->assertInertia(fn (Assert $page) => $page->has('bills.data', 3));
    }

    public function test_conta_vence_no_dia_sem_contar_como_vencida(): void
    {
        $this->assertFalse($this->bill(['due_date' => today()])->fresh()->isOverdue());
    }

    public function test_entrada_gera_conta_a_pagar_parcelada_com_o_total(): void
    {
        $supplier = Supplier::factory()->create(['company_id' => $this->admin->company_id, 'name' => 'Atacado']);
        $product = Product::factory()->create(['company_id' => $this->admin->company_id]);

        $this->actingAs($this->admin)->post('/entradas', [
            'entry_date' => today()->format('Y-m-d'),
            'supplier_id' => $supplier->id,
            'invoice_number' => '555',
            'items' => [['product_id' => $product->id, 'quantity' => '10', 'unit_cost' => '10']],
            'generate_bill' => true,
            'bill_due_date' => today()->addDays(15)->format('Y-m-d'),
            'bill_installments' => 2,
        ])->assertSessionHasNoErrors();

        $entry = StockEntry::sole();
        $bills = Bill::orderBy('installment_number')->get();

        $this->assertCount(2, $bills);
        $this->assertSame(['50.00', '50.00'], $bills->pluck('amount')->all());
        $this->assertSame($entry->id, $bills[0]->stock_entry_id);
        $this->assertSame($supplier->id, $bills[0]->supplier_id);
        $this->assertSame('Fornecedores', $bills[0]->category);
        $this->assertSame("Entrada #{$entry->id} · Nota 555 (1/2)", $bills[0]->fullDescription());
    }

    public function test_entrada_sem_opcao_nao_gera_conta(): void
    {
        $product = Product::factory()->create(['company_id' => $this->admin->company_id]);

        $this->actingAs($this->admin)->post('/entradas', [
            'entry_date' => today()->format('Y-m-d'),
            'items' => [['product_id' => $product->id, 'quantity' => '1', 'unit_cost' => '1']],
        ])->assertSessionHasNoErrors();

        $this->assertSame(0, Bill::count());
    }

    public function test_conta_da_entrada_exige_vencimento(): void
    {
        $product = Product::factory()->create(['company_id' => $this->admin->company_id]);

        $this->actingAs($this->admin)->post('/entradas', [
            'entry_date' => today()->format('Y-m-d'),
            'items' => [['product_id' => $product->id, 'quantity' => '1', 'unit_cost' => '1']],
            'generate_bill' => true,
        ])->assertSessionHasErrors('bill_due_date');

        $this->assertSame(0, StockEntry::count());
    }

    public function test_cancelar_entrada_cancela_contas_abertas(): void
    {
        $entry = StockEntry::factory()->create(['company_id' => $this->admin->company_id]);
        $bill = $this->bill(['stock_entry_id' => $entry->id]);

        $this->actingAs($this->admin)->post("/entradas/{$entry->id}/cancelar", ['reason' => 'Nota errada'])->assertSessionHas('success');

        $this->assertSame(Bill::CANCELLED, $bill->fresh()->status);
        $this->assertSame('Nota errada', $bill->fresh()->cancel_reason);
    }

    public function test_nao_cancela_entrada_com_conta_quitada(): void
    {
        $entry = StockEntry::factory()->create(['company_id' => $this->admin->company_id]);
        $this->bill(['stock_entry_id' => $entry->id, 'status' => Bill::PAID]);

        $this->actingAs($this->admin)->post("/entradas/{$entry->id}/cancelar", ['reason' => 'x'])->assertSessionHasErrors('stock');

        $this->assertFalse($entry->fresh()->isCancelled());
    }

    public function test_fechamento_guarda_contas_pagas_em_dinheiro(): void
    {
        $session = $this->openSession('500');
        $bill = $this->bill();

        $this->actingAs($this->admin);
        $this->post("/financeiro/contas/{$bill->id}/baixa", $this->settlement(['payment_method' => 'cash']));
        $this->post("/caixa/{$session->id}/fechar", ['counted_cash' => '400'])->assertSessionHasNoErrors();

        $session->refresh();
        $this->assertSame('0.00', $session->difference);
        $this->assertSame('100.00', $session->summary['bill_payments']);
    }

    public function test_fluxo_do_dia_soma_contas_recebidas_e_pagas(): void
    {
        $this->bill(['status' => Bill::PAID, 'paid_amount' => '100', 'paid_at' => today()]);
        $this->bill(['type' => Bill::RECEIVABLE, 'status' => Bill::PAID, 'paid_amount' => '30', 'paid_at' => today()]);
        $this->bill(['status' => Bill::PAID, 'paid_amount' => '999', 'paid_at' => today()->subDay()]);

        $this->actingAs($this->admin)->get('/caixa/fluxo')->assertInertia(
            fn (Assert $page) => $page->where('billsPaid', '100.00')
                ->where('billsReceived', '30.00')
                ->where('balance', '-70.00')
        );
    }

    public function test_inicio_mostra_vencidas_para_quem_pode_ver(): void
    {
        $this->bill(['due_date' => today()->subDay()]);

        $this->actingAs($this->admin)->get('/')->assertInertia(fn (Assert $page) => $page->where('finance.payable_overdue.count', 1));

        $this->actingAs($this->userWith([], $this->admin->company))->get('/')
            ->assertInertia(fn (Assert $page) => $page->where('finance', null));
    }

    public function test_permissoes(): void
    {
        $bill = $this->bill();

        $this->actingAs($this->userWith([], $this->admin->company))->get('/financeiro/pagar')->assertForbidden();

        $viewer = $this->userWith(['finance.view'], $this->admin->company);
        $this->actingAs($viewer)->get('/financeiro/pagar')->assertOk();
        $this->get("/financeiro/contas/{$bill->id}")->assertOk();
        $this->post('/financeiro/pagar', $this->payload())->assertForbidden();
        $this->post("/financeiro/contas/{$bill->id}/baixa", $this->settlement())->assertForbidden();
        $this->post("/financeiro/contas/{$bill->id}/cancelar", ['reason' => 'x'])->assertForbidden();
    }

    public function test_auditoria_registra_criacao_baixa_e_cancelamento(): void
    {
        $bill = $this->bill(['description' => 'Energia']);

        $this->actingAs($this->admin);
        $this->post('/financeiro/pagar', $this->payload());
        $this->post("/financeiro/contas/{$bill->id}/baixa", $this->settlement());

        $this->assertDatabaseHas('audit_logs', ['event' => 'created', 'auditable_type' => 'Bill', 'user_id' => $this->admin->id]);

        $log = AuditLog::where('auditable_id', $bill->id)->where('auditable_type', 'Bill')->where('event', 'updated')->sole();
        $this->assertSame('Em aberto', $log->old_values['status']);
        $this->assertSame('Quitada', $log->new_values['status']);
        $this->assertSame('100,00', $log->new_values['paid_amount']);
        $this->assertSame('Pix', $log->new_values['payment_method']);
    }

    public function test_isolamento_entre_empresas(): void
    {
        $other = $this->adminOf();
        $foreign = Bill::factory()->create(['company_id' => $other->company_id]);
        $supplier = Supplier::factory()->create(['company_id' => $other->company_id]);

        $this->actingAs($this->admin);
        $this->get('/financeiro/pagar')->assertInertia(fn (Assert $page) => $page->has('bills.data', 0));
        $this->get("/financeiro/contas/{$foreign->id}")->assertNotFound();
        $this->get("/financeiro/contas/{$foreign->id}/editar")->assertNotFound();
        $this->post("/financeiro/contas/{$foreign->id}/baixa", $this->settlement())->assertNotFound();
        $this->post("/financeiro/contas/{$foreign->id}/cancelar", ['reason' => 'x'])->assertNotFound();
        $this->post('/financeiro/pagar', $this->payload(['supplier_id' => $supplier->id]))->assertSessionHasErrors('supplier_id');

        $this->assertTrue($foreign->fresh()->isOpen());
    }
}
