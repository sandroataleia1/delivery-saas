<?php

namespace Tests\Feature;

use App\Actions\MoveStock;
use App\Actions\SyncRecipe;
use App\Models\AuditLog;
use App\Models\Product;
use App\Models\Sale;
use App\Models\StockMovement;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class SaleTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Product $soda;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->adminOf();
        $this->soda = $this->product(['name' => 'Refrigerante', 'sale_price' => '10', 'cost_price' => '6', 'stock_quantity' => '10']);
        $this->openCash($this->admin);
    }

    private function product(array $attributes = []): Product
    {
        return Product::factory()->create(['company_id' => $this->admin->company_id, ...$attributes]);
    }

    private function sale(array $overrides = []): array
    {
        return [
            'client_uuid' => (string) Str::uuid(),
            'items' => [['product_id' => $this->soda->id, 'quantity' => '2', 'expected_price' => '10.00']],
            'payments' => [['method' => 'pix', 'amount' => '20.00']],
            ...$overrides,
        ];
    }

    public function test_venda_grava_totais_itens_pagamento_e_baixa_estoque(): void
    {
        $this->actingAs($this->admin)->post('/vendas', $this->sale())->assertRedirect(route('pos'));

        $sale = Sale::sole();
        $this->assertSame(1, $sale->number);
        $this->assertSame('20.00', $sale->total);
        $this->assertSame($this->admin->name, $sale->user_name);
        $this->assertSame('Refrigerante', $sale->items()->sole()->product_name);
        $this->assertSame('6.0000', $sale->items()->sole()->unit_cost);
        $this->assertSame('pix', $sale->payments()->sole()->method);
        $this->assertSame('8.000', $this->soda->fresh()->stock_quantity);

        $movement = StockMovement::sole();
        $this->assertSame(StockMovement::SALE, $movement->type);
        $this->assertSame($sale->id, $movement->sale_id);
    }

    public function test_preco_vem_do_banco_e_nao_do_navegador(): void
    {
        $this->actingAs($this->admin)->post('/vendas', $this->sale([
            'items' => [['product_id' => $this->soda->id, 'quantity' => '2', 'expected_price' => '0.01', 'unit_price' => '0.01', 'total' => '0.02']],
            'payments' => [['method' => 'pix', 'amount' => '0.02']],
        ]))->assertSessionHasErrors('items');

        $this->assertSame(0, Sale::count());
        $this->assertSame('10.000', $this->soda->fresh()->stock_quantity);
    }

    public function test_preco_alterado_durante_a_venda_avisa_e_nao_cobra_errado(): void
    {
        $this->soda->sale_price = '12';
        $this->soda->save();

        $this->actingAs($this->admin)->post('/vendas', $this->sale())
            ->assertSessionHasErrors(['items' => 'O preço de Refrigerante mudou para R$ 12,00. Confira o carrinho.']);

        $this->assertSame(0, Sale::count());
    }

    public function test_reenviar_a_mesma_venda_nao_duplica(): void
    {
        $payload = $this->sale();

        $this->actingAs($this->admin);
        $this->post('/vendas', $payload);
        $this->post('/vendas', $payload)->assertRedirect(route('pos'));

        $this->assertSame(1, Sale::count());
        $this->assertSame('8.000', $this->soda->fresh()->stock_quantity);
    }

    public function test_numeracao_e_por_empresa(): void
    {
        $other = $this->adminOf();
        $this->openCash($other);
        $foreign = Product::factory()->create(['company_id' => $other->company_id, 'sale_price' => '10', 'stock_quantity' => '5']);

        $this->actingAs($this->admin);
        $this->post('/vendas', $this->sale());
        $this->post('/vendas', $this->sale());

        $this->actingAs($other)->post('/vendas', $this->sale([
            'items' => [['product_id' => $foreign->id, 'quantity' => '2', 'expected_price' => '10.00']],
        ]))->assertSessionHasNoErrors();

        $this->assertSame([1, 2, 1], Sale::withoutGlobalScopes()->orderBy('id')->pluck('number')->all());
    }

    public function test_dinheiro_calcula_troco_e_grava_o_liquido(): void
    {
        $this->actingAs($this->admin)->post('/vendas', $this->sale([
            'payments' => [['method' => 'cash', 'amount' => '50']],
        ]))->assertSessionHas('completed_sale.change', '30.00');

        $sale = Sale::sole();
        $this->assertSame('30.00', $sale->change);
        $this->assertSame('20.00', $sale->payments()->sole()->amount);
    }

    public function test_pagamento_misto(): void
    {
        $this->actingAs($this->admin)->post('/vendas', $this->sale([
            'payments' => [['method' => 'pix', 'amount' => '15'], ['method' => 'cash', 'amount' => '10']],
        ]))->assertSessionHasNoErrors();

        $sale = Sale::sole();
        $this->assertSame('5.00', $sale->change);
        $this->assertSame('5.00', $sale->payments()->where('method', 'cash')->sole()->amount);
        $this->assertSame('15.00', $sale->payments()->where('method', 'pix')->sole()->amount);
    }

    public function test_pagamento_insuficiente_e_recusado_e_nada_e_baixado(): void
    {
        $this->actingAs($this->admin)->post('/vendas', $this->sale([
            'payments' => [['method' => 'cash', 'amount' => '19.99']],
        ]))->assertSessionHasErrors('payments');

        $this->assertSame(0, Sale::count());
        $this->assertSame('10.000', $this->soda->fresh()->stock_quantity);
    }

    public function test_so_dinheiro_tem_troco(): void
    {
        $this->actingAs($this->admin)->post('/vendas', $this->sale([
            'payments' => [['method' => 'credit', 'amount' => '30']],
        ]))->assertSessionHasErrors('payments');
    }

    public function test_quantidade_fracionada_arredonda_em_centavos(): void
    {
        $product = $this->product(['unit' => 'KG', 'sale_price' => '33.33', 'stock_quantity' => '5']);

        $this->actingAs($this->admin)->post('/vendas', $this->sale([
            'items' => [['product_id' => $product->id, 'quantity' => '0.335', 'expected_price' => '33.33']],
            'payments' => [['method' => 'pix', 'amount' => '11.17']],
        ]))->assertSessionHasNoErrors();

        $this->assertSame('11.17', Sale::sole()->total);
    }

    public function test_desconto_exige_permissao_e_nao_passa_do_subtotal(): void
    {
        $seller = $this->userWith(['sales.create', 'cash.operate'], $this->admin->company);
        $this->openCash($seller);

        $this->actingAs($seller)->post('/vendas', $this->sale([
            'discount' => '5', 'payments' => [['method' => 'pix', 'amount' => '15']],
        ]))->assertSessionHasErrors('discount');

        $this->actingAs($this->admin)->post('/vendas', $this->sale(['discount' => '25']))->assertSessionHasErrors('discount');

        $this->post('/vendas', $this->sale([
            'discount' => '5', 'payments' => [['method' => 'pix', 'amount' => '15']],
        ]))->assertSessionHasNoErrors();

        $this->assertSame('15.00', Sale::sole()->total);
    }

    public function test_venda_sem_estoque_passa_e_deixa_negativo_com_alerta(): void
    {
        $this->actingAs($this->admin)->post('/vendas', $this->sale([
            'items' => [['product_id' => $this->soda->id, 'quantity' => '12', 'expected_price' => '10.00']],
            'payments' => [['method' => 'pix', 'amount' => '120']],
        ]))->assertSessionHas('completed_sale.negative', ['Refrigerante (-2 UN)']);

        $this->assertSame('-2.000', $this->soda->fresh()->stock_quantity);
        $this->assertDatabaseHas('audit_logs', ['event' => 'stock_negative', 'user_id' => $this->admin->id]);
        $this->get('/estoque?low=1')->assertInertia(fn (Assert $page) => $page->where('summary.low', 1)->where('products.data.0.is_negative', true));
    }

    public function test_venda_com_ficha_tecnica_baixa_insumos_mesmo_sem_estoque(): void
    {
        $cheese = $this->product(['name' => 'Queijo', 'unit' => 'KG', 'stock_quantity' => '0.1', 'cost_price' => '30']);
        $pizza = $this->product(['name' => 'Pizza', 'track_stock' => false, 'sale_price' => '40']);
        app(SyncRecipe::class)->handle($pizza, [['ingredient_id' => $cheese->id, 'quantity' => '0.250']]);

        $this->actingAs($this->admin)->post('/vendas', $this->sale([
            'items' => [['product_id' => $pizza->id, 'quantity' => '1', 'expected_price' => '40.00']],
            'payments' => [['method' => 'pix', 'amount' => '40']],
        ]))->assertSessionHas('completed_sale.negative', ['Queijo (-0,15 KG)']);

        $this->assertSame('-0.150', $cheese->fresh()->stock_quantity);
        $this->assertSame('7.5000', Sale::sole()->items()->sole()->unit_cost);
        $this->assertSame(StockMovement::SALE_INGREDIENT, StockMovement::where('sale_id', Sale::sole()->id)->sole()->type);
    }

    public function test_custo_medio_nao_distorce_com_saldo_negativo(): void
    {
        $product = $this->product(['stock_quantity' => '-2', 'cost_price' => '10']);

        $this->actingAs($this->admin)->post('/entradas', [
            'entry_date' => today()->format('Y-m-d'),
            'items' => [['product_id' => $product->id, 'quantity' => '10', 'unit_cost' => '4']],
        ])->assertSessionHasNoErrors();

        $product->refresh();
        $this->assertSame('8.000', $product->stock_quantity);
        $this->assertSame('4.0000', $product->cost_price);
    }

    public function test_entrada_que_nao_zera_o_negativo_mantem_o_custo(): void
    {
        $product = $this->product(['stock_quantity' => '-10', 'cost_price' => '10']);

        $this->actingAs($this->admin)->post('/entradas', [
            'entry_date' => today()->format('Y-m-d'),
            'items' => [['product_id' => $product->id, 'quantity' => '2', 'unit_cost' => '4']],
        ])->assertSessionHasNoErrors();

        $this->assertSame('10.0000', $product->fresh()->cost_price);
    }

    public function test_ajuste_manual_continua_sem_poder_negativar(): void
    {
        $this->actingAs($this->admin)->post("/estoque/{$this->soda->id}/ajuste", [
            'type' => 'loss', 'quantity' => '11', 'notes' => 'x',
        ])->assertSessionHasErrors('stock');
    }

    public function test_cancelar_devolve_o_que_foi_baixado_mesmo_com_ficha_alterada(): void
    {
        $cheese = $this->product(['unit' => 'KG', 'stock_quantity' => '5']);
        $pizza = $this->product(['track_stock' => false, 'sale_price' => '40']);
        app(SyncRecipe::class)->handle($pizza, [['ingredient_id' => $cheese->id, 'quantity' => '0.250']]);

        $this->actingAs($this->admin)->post('/vendas', $this->sale([
            'items' => [['product_id' => $pizza->id, 'quantity' => '2', 'expected_price' => '40.00']],
            'payments' => [['method' => 'pix', 'amount' => '80']],
        ]));

        app(SyncRecipe::class)->handle($pizza, [['ingredient_id' => $cheese->id, 'quantity' => '1']]);

        $sale = Sale::sole();
        $this->post("/vendas/{$sale->id}/cancelar", ['reason' => 'Cliente desistiu'])->assertSessionHas('success');

        $this->assertSame('5.000', $cheese->fresh()->stock_quantity);
        $sale->refresh();
        $this->assertTrue($sale->isCancelled());
        $this->assertSame('Cliente desistiu', $sale->cancel_reason);
        $this->assertSame($this->admin->name, $sale->cancelled_by);
    }

    public function test_cancelamento_exige_motivo_e_nao_repete(): void
    {
        $this->actingAs($this->admin)->post('/vendas', $this->sale());
        $sale = Sale::sole();

        $this->post("/vendas/{$sale->id}/cancelar", [])->assertSessionHasErrors('reason');
        $this->post("/vendas/{$sale->id}/cancelar", ['reason' => 'x']);
        $this->post("/vendas/{$sale->id}/cancelar", ['reason' => 'x'])->assertSessionHasErrors('sale');

        $this->assertSame('10.000', $this->soda->fresh()->stock_quantity);
    }

    public function test_venda_e_cancelamento_ficam_na_auditoria_com_detalhes(): void
    {
        $this->actingAs($this->admin)->post('/vendas', $this->sale([
            'discount' => '5',
            'payments' => [['method' => 'cash', 'amount' => '20']],
        ]));
        $sale = Sale::sole();
        $this->post("/vendas/{$sale->id}/cancelar", ['reason' => 'Erro do caixa']);

        $log = AuditLog::where('event', 'sale')->sole();
        $this->assertSame($this->admin->id, $log->user_id);
        $this->assertSame(['2 UN de Refrigerante a R$ 10,00'], $log->new_values['items']);
        $this->assertSame('5,00', $log->new_values['discount']);
        $this->assertSame(['Dinheiro: R$ 15,00'], $log->new_values['payments']);

        $this->assertSame('Erro do caixa', AuditLog::where('event', 'sale_cancelled')->sole()->new_values['cancel_reason']);
    }

    public function test_permissoes(): void
    {
        $company = $this->admin->company;
        $this->actingAs($this->admin)->post('/vendas', $this->sale());
        $sale = Sale::sole();

        $none = $this->userWith([], $company);
        $this->actingAs($none)->get('/pdv')->assertForbidden();
        $this->post('/vendas', $this->sale())->assertForbidden();
        $this->get('/vendas')->assertForbidden();

        $seller = $this->userWith(['sales.create', 'sales.view', 'cash.operate'], $company);
        $this->openCash($seller);
        $this->actingAs($seller)->get('/pdv')->assertOk();
        $this->get('/vendas')->assertOk();
        $this->get("/vendas/{$sale->id}/cupom")->assertOk();
        $this->post("/vendas/{$sale->id}/cancelar", ['reason' => 'x'])->assertForbidden();
    }

    public function test_custo_so_aparece_com_permissao(): void
    {
        $this->actingAs($this->admin)->post('/vendas', $this->sale());
        $sale = Sale::sole();

        $this->get("/vendas/{$sale->id}")->assertInertia(fn (Assert $page) => $page->where('sale.cost', '12.0000'));

        $this->actingAs($this->userWith(['sales.view'], $this->admin->company))
            ->get("/vendas/{$sale->id}")->assertInertia(fn (Assert $page) => $page->where('sale.cost', null));
    }

    public function test_isolamento_entre_empresas(): void
    {
        $other = $this->adminOf();
        $foreign = Product::factory()->create(['company_id' => $other->company_id]);

        $this->actingAs($this->admin)->post('/vendas', $this->sale());
        $sale = Sale::sole();

        $this->post('/vendas', $this->sale([
            'items' => [['product_id' => $foreign->id, 'quantity' => '1', 'expected_price' => '10.00']],
        ]))->assertSessionHasErrors('items.0.product_id');

        $this->openCash($other);
        $this->actingAs($other);
        $this->get('/vendas')->assertInertia(fn (Assert $page) => $page->has('sales.data', 0)->where('summary.count', 0));
        $this->get("/vendas/{$sale->id}")->assertNotFound();
        $this->get("/vendas/{$sale->id}/cupom")->assertNotFound();
        $this->post("/vendas/{$sale->id}/cancelar", ['reason' => 'x'])->assertNotFound();
        $this->get('/pdv')->assertInertia(fn (Assert $page) => $page->has('products', 1));
    }

    public function test_produto_inativo_nao_e_vendido_nem_aparece(): void
    {
        $this->soda->active = false;
        $this->soda->save();

        $this->actingAs($this->admin)->get('/pdv')->assertInertia(fn (Assert $page) => $page->has('products', 0));
        $this->post('/vendas', $this->sale())->assertSessionHasErrors('items.0.product_id');
    }

    public function test_insumo_nao_aparece_no_pdv_nem_pode_ser_vendido(): void
    {
        $flour = $this->product(['name' => 'Farinha', 'sale_price' => '0']);
        $flour->sellable = false;
        $flour->save();

        $this->actingAs($this->admin)->get('/pdv')->assertInertia(
            fn (Assert $page) => $page->has('products', 1)->where('products.0.name', 'Refrigerante')
        );

        $this->post('/vendas', $this->sale([
            'items' => [['product_id' => $flour->id, 'quantity' => '1', 'expected_price' => '0.00']],
            'payments' => [],
        ]))->assertSessionHasErrors('items.0.product_id');
    }

    public function test_cadastra_insumo_sem_preco_de_venda(): void
    {
        $this->actingAs($this->admin)->post('/produtos', [
            'name' => 'Sal',
            'unit' => 'KG',
            'sellable' => false,
            'sale_price' => '',
            'track_stock' => true,
            'recipe' => [],
        ])->assertSessionHasNoErrors();

        $salt = Product::where('name', 'Sal')->sole();
        $this->assertFalse($salt->sellable);
        $this->assertSame('0.00', $salt->sale_price);
    }

    public function test_cupom_mostra_o_dinheiro_recebido_e_nao_o_liquido(): void
    {
        $this->actingAs($this->admin)->post('/vendas', $this->sale([
            'payments' => [['method' => 'cash', 'amount' => '50']],
        ]));

        $this->get('/vendas/'.Sale::sole()->id.'/cupom')->assertInertia(
            fn (Assert $page) => $page->where('sale.payments.0.received', '50.00')
                ->where('sale.payments.0.amount', '20.00')
                ->where('sale.change', '30.00')
        );
    }

    public function test_listagem_soma_so_vendas_concluidas(): void
    {
        $this->actingAs($this->admin);
        $this->post('/vendas', $this->sale());
        $this->post('/vendas', $this->sale());
        $this->post('/vendas/'.Sale::first()->id.'/cancelar', ['reason' => 'x']);

        $this->get('/vendas')->assertInertia(fn (Assert $page) => $page->has('sales.data', 2)->where('summary.count', 1));
        $this->get('/vendas?status=cancelled')->assertInertia(fn (Assert $page) => $page->has('sales.data', 1));
        $this->get('/vendas?method=credit')->assertInertia(fn (Assert $page) => $page->has('sales.data', 0));
    }

    public function test_acoes_de_estoque_diretas_ainda_bloqueiam_negativo(): void
    {
        $this->expectException(ValidationException::class);

        app(MoveStock::class)->handle($this->soda, StockMovement::LOSS, '-11');
    }
}
