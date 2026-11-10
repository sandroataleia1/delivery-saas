<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\CashMovement;
use App\Models\CashSession;
use App\Models\Product;
use App\Models\Sale;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use LogicException;
use Tests\TestCase;

class CashTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $cashier;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->adminOf();
        $this->cashier = $this->userWith(['sales.create', 'sales.view', 'cash.operate'], $this->admin->company);
        $this->product = Product::factory()->create([
            'company_id' => $this->admin->company_id,
            'sale_price' => '10',
            'stock_quantity' => '100',
        ]);
    }

    private function open(User $user, string $amount = '100'): CashSession
    {
        $this->actingAs($user)->post('/caixa/abrir', ['opening_amount' => $amount])->assertSessionHasNoErrors();

        return CashSession::query()->where('open_user_id', $user->id)->sole();
    }

    private function sell(array $payments = [['method' => 'cash', 'amount' => '20']], string $quantity = '2')
    {
        return $this->post('/vendas', [
            'client_uuid' => (string) Str::uuid(),
            'items' => [['product_id' => $this->product->id, 'quantity' => $quantity, 'expected_price' => '10.00']],
            'payments' => $payments,
        ]);
    }

    public function test_pdv_exige_caixa_aberto(): void
    {
        $this->actingAs($this->cashier)->get('/pdv')->assertRedirect(route('cash.index'));

        $this->sell()->assertSessionHasErrors('cash');

        $this->assertSame(0, Sale::count());
        $this->assertSame('100.000', $this->product->fresh()->stock_quantity);
    }

    public function test_abrir_caixa_libera_o_pdv_e_liga_a_venda_ao_caixa(): void
    {
        $session = $this->open($this->cashier);

        $this->get('/pdv')->assertOk();
        $this->sell()->assertSessionHasNoErrors();

        $this->assertSame($session->id, Sale::sole()->cash_session_id);
        $this->assertSame($this->cashier->name, $session->user_name);
    }

    public function test_um_caixa_aberto_por_operador_mas_varios_na_empresa(): void
    {
        $this->open($this->cashier);

        $this->post('/caixa/abrir', ['opening_amount' => '50'])->assertSessionHasErrors('opening_amount');

        $this->open($this->admin);

        $this->assertSame([1, 2], CashSession::orderBy('number')->pluck('number')->all());
    }

    public function test_banco_impede_dois_caixas_abertos_do_mesmo_operador(): void
    {
        $session = $this->open($this->cashier);

        $this->expectException(UniqueConstraintViolationException::class);

        DB::table('cash_sessions')->insert([
            ...collect($session->getAttributes())->except('id', 'number')->all(),
            'number' => 2,
        ]);
    }

    public function test_venda_de_um_operador_nao_cai_no_caixa_de_outro(): void
    {
        $this->open($this->admin);

        $this->actingAs($this->cashier);
        $this->sell()->assertSessionHasErrors('cash');
    }

    public function test_esperado_soma_abertura_dinheiro_e_suprimento_e_tira_sangria(): void
    {
        $session = $this->open($this->cashier, '100');

        $this->sell([['method' => 'cash', 'amount' => '50']]);
        $this->sell([['method' => 'pix', 'amount' => '20']]);
        $this->post('/caixa/movimentos', ['type' => 'deposit', 'amount' => '30', 'reason' => 'Troco'])->assertSessionHasNoErrors();
        $this->post('/caixa/movimentos', ['type' => 'withdrawal', 'amount' => '40', 'reason' => 'Cofre'])->assertSessionHasNoErrors();

        $summary = $session->calculateSummary();

        $this->assertSame('110.00', $summary['expected_cash']);
        $this->assertSame('20.00', $summary['payments']['cash']);
        $this->assertSame('20.00', $summary['payments']['pix']);
        $this->assertSame('40.00', $summary['sales_total']);
        $this->assertSame(2, $summary['sales_count']);
    }

    public function test_sangria_nao_passa_do_dinheiro_em_caixa_e_exige_motivo(): void
    {
        $this->open($this->cashier, '100');

        $this->post('/caixa/movimentos', ['type' => 'withdrawal', 'amount' => '100.01', 'reason' => 'x'])->assertSessionHasErrors('amount');
        $this->post('/caixa/movimentos', ['type' => 'withdrawal', 'amount' => '10'])->assertSessionHasErrors('reason');
        $this->post('/caixa/movimentos', ['type' => 'refund', 'amount' => '10', 'reason' => 'x'])->assertSessionHasErrors('type');

        $this->assertSame(0, CashMovement::count());
    }

    public function test_fechamento_que_confere(): void
    {
        $session = $this->open($this->cashier, '100');
        $this->sell();

        $this->post("/caixa/{$session->id}/fechar", ['counted_cash' => '120'])->assertRedirect(route('cash.show', $session));

        $session->refresh();
        $this->assertFalse($session->isOpen());
        $this->assertNull($session->open_user_id);
        $this->assertSame('120.00', $session->expected_cash);
        $this->assertSame('0.00', $session->difference);
        $this->assertSame($this->cashier->name, $session->closed_by);

        $this->get('/pdv')->assertRedirect(route('cash.index'));
    }

    public function test_diferenca_exige_justificativa_e_guarda_falta_ou_sobra(): void
    {
        $session = $this->open($this->cashier, '100');

        $this->post("/caixa/{$session->id}/fechar", ['counted_cash' => '90'])->assertSessionHasErrors('closing_notes');
        $this->assertTrue($session->fresh()->isOpen());

        $this->post("/caixa/{$session->id}/fechar", ['counted_cash' => '90', 'closing_notes' => 'Faltou troco'])->assertSessionHasNoErrors();

        $this->assertSame('-10.00', $session->fresh()->difference);
    }

    public function test_fechamento_as_cegas_operador_nao_ve_o_esperado_com_caixa_aberto(): void
    {
        $session = $this->open($this->cashier, '100');

        $this->get('/caixa')->assertInertia(fn (Assert $page) => $page->where('session.expected_cash', null));
        $this->get("/caixa/{$session->id}")->assertInertia(
            fn (Assert $page) => $page->where('session.expected_cash', null)
                ->where('session.sales_total', null)
                ->where('session.payments', [])
        );

        $this->post("/caixa/{$session->id}/fechar", ['counted_cash' => '100']);

        $this->get("/caixa/{$session->id}")->assertInertia(fn (Assert $page) => $page->where('session.expected_cash', '100.00'));
    }

    public function test_gerente_ve_o_esperado_e_fecha_caixa_esquecido(): void
    {
        $session = $this->open($this->cashier, '100');
        $other = $this->userWith(['cash.operate', 'cash.manage'], $this->admin->company);

        $this->actingAs($other)->get("/caixa/{$session->id}")->assertInertia(
            fn (Assert $page) => $page->where('session.expected_cash', '100.00')->where('canClose', true)
        );

        $this->post("/caixa/{$session->id}/fechar", ['counted_cash' => '100'])->assertSessionHasNoErrors();

        $this->assertSame($other->name, $session->fresh()->closed_by);
    }

    public function test_operador_nao_ve_nem_fecha_caixa_de_outro(): void
    {
        $session = $this->open($this->admin, '100');
        $this->actingAs($this->cashier);

        $this->get("/caixa/{$session->id}")->assertForbidden();
        $this->post("/caixa/{$session->id}/fechar", ['counted_cash' => '100'])->assertForbidden();
        $this->get('/caixa/historico')->assertInertia(fn (Assert $page) => $page->has('sessions.data', 0));
    }

    public function test_cancelar_venda_com_caixa_aberto_tira_do_esperado_sem_estorno(): void
    {
        $session = $this->open($this->admin, '100');
        $this->sell();

        $this->post('/vendas/'.Sale::sole()->id.'/cancelar', ['reason' => 'x'])->assertSessionHasNoErrors();

        $this->assertSame('100.00', $session->calculateSummary()['expected_cash']);
        $this->assertSame(0, CashMovement::count());
    }

    public function test_cancelar_venda_de_caixa_fechado_estorna_do_caixa_aberto_e_nao_muda_o_fechado(): void
    {
        $first = $this->open($this->admin, '100');
        $this->sell();
        $this->post("/caixa/{$first->id}/fechar", ['counted_cash' => '120']);
        $sale = Sale::sole();

        $this->post("/vendas/{$sale->id}/cancelar", ['reason' => 'x'])->assertSessionHasErrors('sale');
        $this->assertFalse($sale->fresh()->isCancelled());

        $second = $this->open($this->admin, '50');
        $this->post("/vendas/{$sale->id}/cancelar", ['reason' => 'Devolução'])->assertSessionHasNoErrors();

        $refund = CashMovement::sole();
        $this->assertSame(CashMovement::REFUND, $refund->type);
        $this->assertSame('20.00', $refund->amount);
        $this->assertSame($second->id, $refund->cash_session_id);
        $this->assertSame($sale->id, $refund->sale_id);
        $this->assertSame('30.00', $second->calculateSummary()['expected_cash']);

        $this->assertSame('120.00', $first->fresh()->expected_cash);
        $this->assertSame('20.00', $first->fresh()->summary['sales_total']);
    }

    public function test_venda_em_pix_de_caixa_fechado_cancela_sem_mexer_no_dinheiro(): void
    {
        $session = $this->open($this->admin, '100');
        $this->sell([['method' => 'pix', 'amount' => '20']]);
        $this->post("/caixa/{$session->id}/fechar", ['counted_cash' => '100']);

        $this->post('/vendas/'.Sale::sole()->id.'/cancelar', ['reason' => 'x'])->assertSessionHasNoErrors();

        $this->assertSame(0, CashMovement::count());
    }

    public function test_fluxo_do_dia_nao_conta_cancelamento_duas_vezes(): void
    {
        $this->travelTo(now()->setTime(12, 0));
        $session = $this->open($this->admin, '100');
        $this->sell();
        $this->sell([['method' => 'pix', 'amount' => '20']]);
        $this->post("/caixa/{$session->id}/fechar", ['counted_cash' => '125', 'closing_notes' => 'Sobrou']);
        $saleDay = today()->format('Y-m-d');

        $this->travel(1)->days();
        $this->open($this->admin, '50');
        $this->post('/vendas/'.Sale::first()->id.'/cancelar', ['reason' => 'x'])->assertSessionHasNoErrors();
        $nextDay = today()->format('Y-m-d');

        $this->get("/caixa/fluxo?date=$saleDay")->assertInertia(
            fn (Assert $page) => $page->where('gross', '40.00')
                ->where('net', '40.00')
                ->where('differences', '5.00')
        );

        $this->get("/caixa/fluxo?date=$nextDay")->assertInertia(
            fn (Assert $page) => $page->where('gross', '0.00')
                ->where('net', '-20.00')
                ->where('refunds', '20.00')
        );
    }

    public function test_cancelamento_no_mesmo_dia_some_do_fluxo(): void
    {
        $this->open($this->admin, '100');
        $this->sell();
        $this->post('/vendas/'.Sale::sole()->id.'/cancelar', ['reason' => 'x']);

        $this->get('/caixa/fluxo')->assertInertia(fn (Assert $page) => $page->where('gross', '0.00')->where('net', '0.00'));
    }

    public function test_permissoes(): void
    {
        $none = $this->userWith(['sales.create'], $this->admin->company);

        $this->actingAs($none)->get('/caixa')->assertForbidden();
        $this->post('/caixa/abrir', ['opening_amount' => '1'])->assertForbidden();
        $this->get('/pdv')->assertRedirect(route('dashboard'));

        $this->actingAs($this->cashier)->get('/caixa/fluxo')->assertForbidden();
    }

    public function test_lancamento_nao_pode_ser_alterado_nem_excluido(): void
    {
        $this->open($this->cashier);
        $this->post('/caixa/movimentos', ['type' => 'deposit', 'amount' => '10', 'reason' => 'x']);
        $movement = CashMovement::sole();

        try {
            $movement->reason = 'adulterado';
            $movement->save();
            $this->fail('Deveria ter bloqueado a alteração.');
        } catch (LogicException) {
        }

        $this->expectException(LogicException::class);
        $movement->delete();
    }

    public function test_auditoria_registra_abertura_sangria_e_fechamento(): void
    {
        $session = $this->open($this->cashier, '100');
        $this->post('/caixa/movimentos', ['type' => 'withdrawal', 'amount' => '40', 'reason' => 'Cofre']);
        $this->post("/caixa/{$session->id}/fechar", ['counted_cash' => '50', 'closing_notes' => 'Faltou']);

        $opening = AuditLog::where('auditable_type', 'CashSession')->where('event', 'created')->sole();
        $this->assertSame('100,00', $opening->new_values['opening_amount']);

        $withdrawal = AuditLog::where('auditable_type', 'CashMovement')->sole();
        $this->assertStringContainsString('Sangria de R$ 40,00', $withdrawal->description);
        $this->assertSame('Cofre', $withdrawal->new_values['reason']);

        $closing = AuditLog::where('auditable_type', 'CashSession')->where('event', 'updated')->sole();
        $this->assertSame($this->cashier->id, $closing->user_id);
        $this->assertSame('60,00', $closing->new_values['expected_cash']);
        $this->assertSame('-10,00', $closing->new_values['difference']);
        $this->assertSame('Fechado', $closing->new_values['status']);
    }

    public function test_estorno_de_sangria_e_suprimento_corrige_o_esperado(): void
    {
        $session = $this->open($this->cashier, '100');
        $this->post('/caixa/movimentos', ['type' => 'deposit', 'amount' => '30', 'reason' => 'Troco']);
        $this->post('/caixa/movimentos', ['type' => 'withdrawal', 'amount' => '40', 'reason' => 'Cofre']);
        [$deposit, $withdrawal] = CashMovement::orderBy('id')->get()->all();

        $this->post("/caixa/movimentos/{$withdrawal->id}/estornar", ['reason' => 'Valor errado'])->assertSessionHasNoErrors();

        $reversal = CashMovement::latest('id')->first();
        $this->assertSame(CashMovement::WITHDRAWAL_REVERSAL, $reversal->type);
        $this->assertSame($withdrawal->id, $reversal->reverses_movement_id);
        $this->assertSame('40.00', $reversal->amount);
        $this->assertSame('Valor errado', $reversal->reason);
        $this->assertSame('130.00', $session->calculateSummary()['expected_cash']);
        $this->assertSame('0.00', $session->calculateSummary()['withdrawals']);

        $this->post("/caixa/movimentos/{$deposit->id}/estornar", ['reason' => 'Não entrou'])->assertSessionHasNoErrors();

        $summary = $session->calculateSummary();
        $this->assertSame('100.00', $summary['expected_cash']);
        $this->assertSame('0.00', $summary['deposits']);
        $this->actingAs($this->admin)->get('/caixa/fluxo')->assertInertia(fn (Assert $page) => $page->where('deposits', '0.00')->where('withdrawals', '0.00'));
    }

    public function test_estorno_exige_motivo_vale_uma_vez_e_so_para_sangria_ou_suprimento(): void
    {
        $session = $this->open($this->cashier, '100');
        $this->post('/caixa/movimentos', ['type' => 'withdrawal', 'amount' => '10', 'reason' => 'Cofre']);
        $withdrawal = CashMovement::sole();

        $this->post("/caixa/movimentos/{$withdrawal->id}/estornar", [])->assertSessionHasErrors('reason');
        $this->post("/caixa/movimentos/{$withdrawal->id}/estornar", ['reason' => 'Erro'])->assertSessionHasNoErrors();
        $this->post("/caixa/movimentos/{$withdrawal->id}/estornar", ['reason' => 'Erro'])->assertSessionHasErrors('movement');
        $reversal = CashMovement::latest('id')->first();
        $this->post("/caixa/movimentos/{$reversal->id}/estornar", ['reason' => 'Erro'])->assertSessionHasErrors('movement');

        $this->assertSame(2, CashMovement::count());
        $this->assertSame('100.00', $session->calculateSummary()['expected_cash']);
    }

    public function test_estorno_de_suprimento_nao_deixa_a_gaveta_negativa(): void
    {
        $this->open($this->cashier, '0');
        $this->post('/caixa/movimentos', ['type' => 'deposit', 'amount' => '50', 'reason' => 'Troco']);
        $deposit = CashMovement::sole();
        $this->post('/caixa/movimentos', ['type' => 'withdrawal', 'amount' => '50', 'reason' => 'Cofre']);

        $this->post("/caixa/movimentos/{$deposit->id}/estornar", ['reason' => 'Erro'])->assertSessionHasErrors('amount');

        $this->assertSame(2, CashMovement::count());
    }

    public function test_estorno_so_no_caixa_aberto_de_quem_opera(): void
    {
        $session = $this->open($this->cashier, '100');
        $this->post('/caixa/movimentos', ['type' => 'withdrawal', 'amount' => '10', 'reason' => 'Cofre']);
        $withdrawal = CashMovement::sole();
        $colleague = $this->userWith(['cash.operate'], $this->admin->company);

        $this->actingAs($colleague)->post("/caixa/movimentos/{$withdrawal->id}/estornar", ['reason' => 'Erro'])->assertForbidden();
        $this->actingAs($this->adminOf())->post("/caixa/movimentos/{$withdrawal->id}/estornar", ['reason' => 'Erro'])->assertNotFound();

        $this->actingAs($this->cashier)->post("/caixa/{$session->id}/fechar", ['counted_cash' => '90'])->assertSessionHasNoErrors();
        $this->post("/caixa/movimentos/{$withdrawal->id}/estornar", ['reason' => 'Erro'])->assertSessionHasErrors('amount');

        $this->assertSame(1, CashMovement::count());
    }

    public function test_pdv_traz_o_caixa_com_lancamentos_e_vendas(): void
    {
        $this->open($this->cashier, '100');
        $this->sell([['method' => 'cash', 'amount' => '20']]);
        $this->post('/caixa/movimentos', ['type' => 'withdrawal', 'amount' => '10', 'reason' => 'Cofre']);

        $this->get('/pdv')->assertInertia(fn (Assert $page) => $page
            ->where('cash.number', 1)
            ->where('cash.expected_cash', null)
            ->where('cash.movements.0.label', 'Sangria')
            ->where('cash.movements.0.reversible', true)
            ->where('cash.sales.0.total', '20.00')
            ->where('cash.sales.0.cancelled', false));
    }

    public function test_isolamento_entre_empresas(): void
    {
        $session = $this->open($this->cashier, '100');
        $other = $this->adminOf();

        $this->actingAs($other);
        $this->get("/caixa/{$session->id}")->assertNotFound();
        $this->post("/caixa/{$session->id}/fechar", ['counted_cash' => '1'])->assertNotFound();
        $this->get('/caixa/historico')->assertInertia(fn (Assert $page) => $page->has('sessions.data', 0));
        $this->get('/caixa/fluxo')->assertInertia(fn (Assert $page) => $page->has('sessions', 0));
    }
}
