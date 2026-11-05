<?php

namespace Tests\Feature;

use App\Actions\CreateBills;
use App\Actions\MoveStock;
use App\Models\AuditLog;
use App\Models\Bill;
use App\Models\CashSession;
use App\Models\Company;
use App\Models\Customer;
use App\Models\DeliveryOrder;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\StockEntry;
use App\Models\StockMovement;
use App\Models\User;
use App\Reports\Csv;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ReportTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->adminOf();
        $this->product = Product::factory()->create(['company_id' => $this->admin->company_id, 'name' => 'Pizza', 'sale_price' => '40', 'cost_price' => '10', 'stock_quantity' => '100']);
    }

    private function sale(array $attributes = [], ?Company $company = null): Sale
    {
        static $number = 0;
        $number++;

        $sale = new Sale;
        $sale->forceFill([
            'company_id' => ($company ?? $this->admin->company)->id,
            'number' => $number,
            'client_uuid' => fake()->uuid(),
            'subtotal' => '40',
            'total' => '40',
            'status' => Sale::COMPLETED,
            'user_name' => 'Ana',
            ...$attributes,
        ])->save();

        $item = new SaleItem([
            'company_id' => $sale->company_id,
            'product_id' => $this->product->id,
            'product_name' => 'Pizza',
            'unit' => 'UN',
            'quantity' => '1',
            'unit_price' => '40',
            'unit_cost' => '10',
            'total' => '40',
        ]);
        $sale->items()->save($item);
        $sale->payments()->create(['company_id' => $sale->company_id, 'method' => 'pix', 'amount' => $sale->total]);

        return $sale;
    }

    public function test_vendas_soma_so_as_concluidas_do_periodo_e_da_empresa(): void
    {
        $this->sale(['total' => '40']);
        $this->sale(['total' => '60', 'discount' => '5']);
        $this->sale(['status' => Sale::CANCELLED, 'total' => '999']);
        $this->sale(['created_at' => now()->subYear()]);
        $this->sale(['total' => '500'], Company::factory()->create());

        $this->actingAs($this->admin)->get('/relatorios/vendas')->assertInertia(
            fn (Assert $page) => $page->component('reports/Sales')
                ->where('summary.count', 2)
                ->where('summary.total', '100.00')
                ->where('summary.average', '50.00')
                ->where('summary.discount', '5.00')
                ->where('summary.cancelled_count', 1)
                ->where('summary.cancelled_total', '999.00')
                ->where('payments.0.total', '100.00')
        );
    }

    public function test_grafico_diario_preenche_dias_sem_venda(): void
    {
        $this->travelTo(now()->setDate(2026, 11, 10)->setTime(12, 0));
        $this->sale(['created_at' => now()->subDays(2)]);

        $this->actingAs($this->admin)->get('/relatorios/vendas?from=2026-11-07&to=2026-11-10')->assertInertia(
            fn (Assert $page) => $page->has('daily', 4)
                ->where('daily.0.total', '0.00')
                ->where('daily.1.total', '40.00')
                ->where('daily.1.label', '08/11')
                ->where('daily.3.total', '0.00')
        );
    }

    public function test_horario_de_pico(): void
    {
        $this->sale(['created_at' => now()->setTime(20, 15)]);

        $this->actingAs($this->admin)->get('/relatorios/vendas?from='.today()->format('Y-m-d'))->assertInertia(
            fn (Assert $page) => $page->has('hourly', 24)->where('hourly.20.count', 1)->where('hourly.19.count', 0)
        );
    }

    public function test_venda_conta_por_origem(): void
    {
        $this->sale(['origin' => 'table']);
        $this->sale(['origin' => 'delivery', 'total' => '100']);

        $this->actingAs($this->admin)->get('/relatorios/vendas')->assertInertia(
            fn (Assert $page) => $page->where('origins.0.label', 'Entregas')->where('origins.1.label', 'Mesas')
        );
    }

    public function test_periodo_invalido_e_recusado(): void
    {
        $this->actingAs($this->admin);

        $this->get('/relatorios/vendas?from=2026-02-01&to=2026-01-01')->assertSessionHasErrors('to');
        $this->get('/relatorios/vendas?from=2020-01-01&to=2026-01-01')->assertStatus(422);
    }

    public function test_produtos_com_margem(): void
    {
        $this->sale();
        $this->sale();

        $this->actingAs($this->admin)->get('/relatorios/produtos')->assertInertia(
            fn (Assert $page) => $page->where('products.0.name', 'Pizza')
                ->where('products.0.quantity', 2)
                ->where('products.0.revenue', '80.00')
                ->where('products.0.cost', '20.00')
                ->where('products.0.margin', '60.00')
                ->where('products.0.margin_percent', 75)
                ->where('summary.without_cost', 0)
        );
    }

    public function test_sem_permissao_financeira_nao_ve_custo(): void
    {
        $this->sale();

        $this->actingAs($this->userWith(['reports.view'], $this->admin->company))->get('/relatorios/produtos')->assertInertia(
            fn (Assert $page) => $page->where('products.0.cost', null)->where('products.0.margin', null)->where('withCost', false)
        );
    }

    public function test_avisa_produto_vendido_sem_custo(): void
    {
        $sale = $this->sale();
        $sale->items()->update(['unit_cost' => 0]);

        $this->actingAs($this->admin)->get('/relatorios/produtos')->assertInertia(fn (Assert $page) => $page->where('summary.without_cost', 1));
    }

    public function test_resultado_nao_duplica_compra_de_mercadoria_nem_fiado(): void
    {
        $this->sale(['total' => '40']);

        $entryBill = Bill::factory()->create([
            'company_id' => $this->admin->company_id,
            'category' => 'Fornecedores',
            'stock_entry_id' => StockEntry::factory()->create(['company_id' => $this->admin->company_id])->id,
            'status' => Bill::PAID, 'paid_amount' => '500', 'paid_at' => today(),
        ]);
        Bill::factory()->create(['company_id' => $this->admin->company_id, 'category' => 'Aluguel', 'status' => Bill::PAID, 'paid_amount' => '15', 'paid_at' => today()]);
        Bill::factory()->create(['company_id' => $this->admin->company_id, 'type' => Bill::RECEIVABLE, 'category' => 'Vendas a prazo', 'sale_id' => $this->sale(['total' => '10', 'status' => Sale::CANCELLED])->id, 'status' => Bill::PAID, 'paid_amount' => '700', 'paid_at' => today()]);
        Bill::factory()->create(['company_id' => $this->admin->company_id, 'type' => Bill::RECEIVABLE, 'category' => 'Serviços', 'status' => Bill::PAID, 'paid_amount' => '5', 'paid_at' => today()]);

        $this->actingAs($this->admin)->get('/relatorios/financeiro')->assertInertia(
            fn (Assert $page) => $page->where('statement.revenue', '40.00')
                ->where('statement.cost', '10.00')
                ->where('statement.gross_profit', '30.00')
                ->where('statement.other_income', '5.00')
                ->where('statement.expenses', '15.00')
                ->where('statement.result', '20.00')
                ->where('expenses.0.label', 'Aluguel')
                ->where('purchases', '500.00')
        );

        $this->assertTrue($entryBill->isPaid());
    }

    public function test_devedores_e_contas_em_aberto(): void
    {
        $customer = Customer::factory()->create(['company_id' => $this->admin->company_id, 'name' => 'Marcos']);
        Bill::factory()->create(['company_id' => $this->admin->company_id, 'type' => Bill::RECEIVABLE, 'customer_id' => $customer->id, 'amount' => '75', 'due_date' => today()->subDay()]);

        $this->actingAs($this->admin)->get('/relatorios/financeiro')->assertInertia(
            fn (Assert $page) => $page->where('debtors.0.label', 'Marcos')
                ->where('debtors.0.total', '75.00')
                ->where('open.receivable', '75.00')
                ->where('open.receivable_overdue', '75.00')
        );
    }

    public function test_estoque_mostra_alertas_e_perdas(): void
    {
        $this->actingAs($this->admin);
        app(MoveStock::class)->handle($this->product, StockMovement::LOSS, '-4');
        $low = Product::factory()->create(['company_id' => $this->admin->company_id, 'stock_quantity' => '-2']);

        $this->get('/relatorios/estoque')->assertInertia(
            fn (Assert $page) => $page->where('summary.negative', 1)
                ->where('summary.losses', '40.00')
                ->where('losses.0.name', 'Pizza')
                ->where('losses.0.quantity', 4)
                ->where('alerts.0.id', $low->id)
                ->where('alerts.0.is_negative', true)
        );
    }

    public function test_caixa_agrupa_diferencas_por_operador(): void
    {
        foreach ([['Ana', '-5'], ['Ana', '-10'], ['Bia', '0'], ['Bia', '3']] as $index => [$name, $difference]) {
            $session = new CashSession;
            $session->forceFill([
                'company_id' => $this->admin->company_id,
                'number' => $index + 1,
                'user_id' => $this->admin->id,
                'user_name' => $name,
                'opening_amount' => '0',
                'status' => CashSession::CLOSED,
                'opened_at' => now(),
                'closed_at' => now(),
                'expected_cash' => '100',
                'counted_cash' => '100',
                'difference' => $difference,
            ])->saveQuietly();
        }

        $this->actingAs($this->admin)->get('/relatorios/caixa')->assertInertia(
            fn (Assert $page) => $page->where('summary.sessions', 4)
                ->where('summary.with_difference', 3)
                ->where('summary.short', '-15.00')
                ->where('summary.over', '3.00')
                ->where('operators.0.label', 'Ana')
                ->where('operators.0.total', '-15.00')
                ->has('sessions', 3)
        );
    }

    public function test_entregas_por_entregador_e_tempo(): void
    {
        $customer = Customer::factory()->create(['company_id' => $this->admin->company_id]);

        foreach ([10, 20] as $index => $routeMinutes) {
            $order = new DeliveryOrder;
            $order->forceFill([
                'company_id' => $this->admin->company_id,
                'number' => $index + 1,
                'client_uuid' => fake()->uuid(),
                'customer_id' => $customer->id,
                'type' => DeliveryOrder::DELIVERY,
                'status' => DeliveryOrder::DELIVERED,
                'customer_name' => 'X',
                'district' => 'Centro',
                'subtotal' => '30',
                'delivery_fee' => '5',
                'payment_method' => 'pix',
                'courier_name' => 'Elias',
                'user_name' => 'Ana',
                'dispatched_at' => now()->subMinutes($routeMinutes),
                'delivered_at' => now(),
                'created_at' => now()->subMinutes(40),
            ])->saveQuietly();
        }

        $this->actingAs($this->admin)->get('/relatorios/entregas')->assertInertia(
            fn (Assert $page) => $page->where('summary.deliveries', 2)
                ->where('summary.fees', '10.00')
                ->where('summary.average_minutes', 40)
                ->where('couriers.0.label', 'Elias')
                ->where('couriers.0.count', 2)
                ->where('couriers.0.route_minutes', 15)
                ->where('districts.0.total', '70.00')
        );
    }

    public function test_permissoes(): void
    {
        $company = $this->admin->company;

        $this->actingAs($this->userWith([], $company))->get('/relatorios/vendas')->assertForbidden();

        $viewer = $this->userWith(['reports.view'], $company);
        $this->actingAs($viewer);
        $this->get('/relatorios')->assertRedirect(route('reports.sales'));
        $this->get('/relatorios/vendas')->assertOk();
        $this->get('/relatorios/financeiro')->assertForbidden();
        $this->get('/relatorios/caixa')->assertForbidden();
        $this->get('/relatorios/financeiro/exportar')->assertForbidden();

        $finance = $this->userWith(['reports.finance'], $company);
        $this->actingAs($finance);
        $this->get('/relatorios')->assertRedirect(route('reports.finance'));
        $this->get('/relatorios/financeiro')->assertOk();
        $this->get('/relatorios/vendas')->assertForbidden();
    }

    public function test_exportar_gera_planilha_em_portugues_e_fica_na_auditoria(): void
    {
        $this->sale(['total' => '1234.5', 'subtotal' => '1234.5']);

        $response = $this->actingAs($this->admin)->get('/relatorios/vendas/exportar');
        $content = $response->streamedContent();

        $response->assertOk();
        $this->assertStringStartsWith("\xEF\xBB\xBF", $content);
        $this->assertStringContainsString('Venda;Data;Origem', $content);
        $this->assertStringContainsString('1234,50', $content);
        $this->assertStringContainsString('Pix 1234,50', $content);
        $this->assertSame(1, AuditLog::where('event', 'report_exported')->where('user_id', $this->admin->id)->count());
    }

    public function test_exportacao_sem_permissao_financeira_nao_leva_custo(): void
    {
        $this->sale();

        $content = $this->actingAs($this->userWith(['reports.view'], $this->admin->company))->get('/relatorios/produtos/exportar')->streamedContent();

        $this->assertStringNotContainsString('Custo', $content);
        $this->assertStringNotContainsString('Margem', $content);
    }

    public function test_exportacao_neutraliza_formula_no_nome_do_cliente(): void
    {
        $customer = Customer::factory()->create(['company_id' => $this->admin->company_id, 'name' => '=HYPERLINK("http://mal")']);
        $this->sale(['customer_id' => $customer->id]);

        $content = $this->actingAs($this->admin)->get('/relatorios/vendas/exportar')->streamedContent();

        $this->assertStringContainsString("'=HYPERLINK", $content);
    }

    public function test_csv_mantem_numero_negativo(): void
    {
        $this->assertSame('-5,00', Csv::money('-5'));

        $method = new \ReflectionMethod(Csv::class, 'cell');

        $this->assertSame('-5,00', $method->invoke(null, '-5,00'));
        $this->assertSame("'-cmd", $method->invoke(null, '-cmd'));
    }

    public function test_exportacao_nao_mistura_empresas(): void
    {
        $other = Company::factory()->create();
        $this->sale(['total' => '777', 'subtotal' => '777'], $other);

        $content = $this->actingAs($this->admin)->get('/relatorios/vendas/exportar')->streamedContent();

        $this->assertStringNotContainsString('777', $content);
    }

    public function test_todas_as_exportacoes_funcionam(): void
    {
        $this->sale();
        $this->actingAs($this->admin);

        foreach (['vendas', 'produtos', 'financeiro', 'estoque', 'caixa', 'entregas'] as $report) {
            $this->get("/relatorios/$report/exportar")->assertOk();
        }

        $this->get('/relatorios/outro/exportar')->assertNotFound();
    }

    public function test_contas_parceladas_usam_o_valor_pago_no_mes_da_baixa(): void
    {
        $this->actingAs($this->admin);
        app(CreateBills::class)->handle(['type' => Bill::PAYABLE, 'description' => 'Forno', 'category' => 'Manutenção', 'amount' => '100', 'due_date' => today()->format('Y-m-d')], 2);

        $this->get('/relatorios/financeiro')->assertInertia(fn (Assert $page) => $page->where('statement.expenses', '0.00'));
    }
}
