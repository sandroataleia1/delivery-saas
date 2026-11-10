<?php

namespace Tests\Feature;

use App\Actions\SyncRecipe;
use App\Models\AuditLog;
use App\Models\DiningTable;
use App\Models\Product;
use App\Models\Sale;
use App\Models\StockMovement;
use App\Models\TableOrder;
use App\Models\TableOrderItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class TableTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private DiningTable $table;

    private Product $soda;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->productionAdminOf();
        $this->table = DiningTable::factory()->create(['company_id' => $this->admin->company_id, 'number' => 5]);
        $this->soda = Product::factory()->create([
            'company_id' => $this->admin->company_id,
            'name' => 'Refrigerante',
            'sale_price' => '10',
            'stock_quantity' => '20',
        ]);
        $this->openCash($this->admin);
    }

    private function openTable(?User $user = null): TableOrder
    {
        $this->actingAs($user ?? $this->admin)->post("/mesas/{$this->table->id}/abrir", ['people' => 2])->assertSessionHasNoErrors();

        return TableOrder::where('open_table_id', $this->table->id)->sole();
    }

    private function launch(TableOrder $order, string $quantity = '2', array $overrides = [])
    {
        return $this->post("/mesas/comandas/{$order->id}/itens", [
            'client_uuid' => (string) Str::uuid(),
            'items' => [['product_id' => $this->soda->id, 'quantity' => $quantity, ...$overrides]],
        ]);
    }

    private function closing(array $overrides = []): array
    {
        foreach (TableOrder::where('status', 'open')->get() as $activeOrder) {
            $this->deliverProduction($activeOrder);
        }

        return [
            'client_uuid' => (string) Str::uuid(),
            'expected_subtotal' => '20.00',
            'charge_service' => false,
            'payments' => [['method' => 'pix', 'amount' => '20']],
            ...$overrides,
        ];
    }

    public function test_abrir_mesa_e_lancar_itens(): void
    {
        $order = $this->openTable();

        $this->launch($order, '2', ['notes' => 'Sem gelo'])->assertSessionHasNoErrors();

        $item = $order->items()->sole();
        $this->assertSame('Refrigerante', $item->product_name);
        $this->assertSame('20.00', $item->total);
        $this->assertSame('Sem gelo', $item->notes);
        $this->assertSame($this->admin->name, $item->user_name);
        $this->assertSame('20.00', $order->subtotal());
    }

    public function test_mesa_ocupada_nao_abre_de_novo(): void
    {
        $this->openTable();

        $this->post("/mesas/{$this->table->id}/abrir", ['people' => 2])->assertSessionHasErrors('table');

        $this->assertSame(1, TableOrder::count());
    }

    public function test_mesa_inativa_nao_abre(): void
    {
        $this->table->forceFill(['active' => false])->saveQuietly();

        $this->actingAs($this->admin)->post("/mesas/{$this->table->id}/abrir", ['people' => 1])->assertSessionHasErrors('table');
    }

    public function test_lancar_nao_baixa_estoque(): void
    {
        $this->launch($this->openTable(), '5');

        $this->assertSame('20.000', $this->soda->fresh()->stock_quantity);
        $this->assertSame(0, StockMovement::count());
    }

    public function test_reenviar_o_mesmo_lancamento_nao_duplica(): void
    {
        $order = $this->openTable();
        $payload = [
            'client_uuid' => (string) Str::uuid(),
            'items' => [['product_id' => $this->soda->id, 'quantity' => '1']],
        ];

        $this->post("/mesas/comandas/{$order->id}/itens", $payload);
        $this->post("/mesas/comandas/{$order->id}/itens", $payload)->assertSessionHasNoErrors();

        $this->assertSame(1, $order->items()->count());
    }

    public function test_insumo_nao_vai_para_a_comanda(): void
    {
        $flour = Product::factory()->create(['company_id' => $this->admin->company_id]);
        $flour->sellable = false;
        $flour->save();

        $order = $this->openTable();

        $this->post("/mesas/comandas/{$order->id}/itens", [
            'client_uuid' => (string) Str::uuid(),
            'items' => [['product_id' => $flour->id, 'quantity' => '1']],
        ])->assertSessionHasErrors('items.0.product_id');
    }

    public function test_fechar_conta_gera_venda_baixa_estoque_e_libera_a_mesa(): void
    {
        $order = $this->openTable();
        $this->launch($order);

        $this->post("/mesas/comandas/{$order->id}/fechar", $this->closing())
            ->assertRedirect(route('tables.index'))
            ->assertSessionHas('completed_sale.total', '20.00');

        $sale = Sale::sole();
        $this->assertSame('table', $sale->origin);
        $this->assertSame(5, $sale->table_number);
        $this->assertSame('18.000', $this->soda->fresh()->stock_quantity);

        $order->refresh();
        $this->assertSame(TableOrder::CLOSED, $order->status);
        $this->assertNull($order->open_table_id);
        $this->assertSame($sale->id, $order->sale_id);

        $this->post("/mesas/{$this->table->id}/abrir", ['people' => 1])->assertSessionHasNoErrors();
    }

    public function test_preco_fica_o_do_lancamento_mesmo_se_mudar_depois(): void
    {
        $order = $this->openTable();
        $this->launch($order);

        $this->soda->sale_price = '15';
        $this->soda->save();

        $this->post("/mesas/comandas/{$order->id}/fechar", $this->closing())->assertSessionHasNoErrors();

        $this->assertSame('10.00', Sale::sole()->items()->sole()->unit_price);
        $this->assertSame('20.00', Sale::sole()->total);
    }

    public function test_mesmo_produto_em_precos_diferentes_vira_duas_linhas(): void
    {
        $order = $this->openTable();
        $this->launch($order, '1');
        $this->soda->sale_price = '15';
        $this->soda->save();
        $this->launch($order, '1');

        $this->post("/mesas/comandas/{$order->id}/fechar", $this->closing([
            'expected_subtotal' => '25.00',
            'payments' => [['method' => 'pix', 'amount' => '25']],
        ]))->assertSessionHasNoErrors();

        $this->assertSame(['10.00', '15.00'], Sale::sole()->items()->orderBy('id')->pluck('unit_price')->all());
        $this->assertSame('18.000', $this->soda->fresh()->stock_quantity);
    }

    public function test_taxa_de_servico_opcional_e_auditada(): void
    {
        $order = $this->openTable();
        $this->launch($order);

        $this->post("/mesas/comandas/{$order->id}/fechar", $this->closing([
            'charge_service' => true,
            'discount' => '5',
            'payments' => [['method' => 'pix', 'amount' => '16.50']],
        ]))->assertSessionHasNoErrors();

        $sale = Sale::sole();
        $this->assertSame('1.50', $sale->service_fee);
        $this->assertSame('16.50', $sale->total);
        $this->assertSame('1,50', AuditLog::where('event', 'sale')->sole()->new_values['service_fee']);
    }

    public function test_percentual_da_taxa_vem_da_empresa(): void
    {
        $this->admin->company->forceFill(['service_fee_percent' => 12])->save();
        $order = $this->openTable();
        $this->launch($order);

        $this->post("/mesas/comandas/{$order->id}/fechar", $this->closing([
            'charge_service' => true,
            'payments' => [['method' => 'pix', 'amount' => '22.40']],
        ]))->assertSessionHasNoErrors();

        $this->assertSame('2.40', Sale::sole()->service_fee);
    }

    public function test_fechar_exige_caixa_aberto_de_quem_fecha(): void
    {
        $operator = $this->userWith(['tables.operate', 'tables.close'], $this->admin->company);
        $order = $this->openTable();
        $this->launch($order);

        $this->actingAs($operator)->post("/mesas/comandas/{$order->id}/fechar", $this->closing())->assertSessionHasErrors('cash');

        $this->assertTrue($order->fresh()->isOpen());
        $this->assertSame(0, Sale::count());
    }

    public function test_comanda_alterada_durante_o_fechamento_e_recusada(): void
    {
        $order = $this->openTable();
        $this->launch($order);

        $this->post("/mesas/comandas/{$order->id}/fechar", $this->closing(['expected_subtotal' => '10.00']))
            ->assertSessionHasErrors('order');

        $this->assertTrue($order->fresh()->isOpen());
    }

    public function test_reenviar_o_fechamento_nao_gera_outra_venda(): void
    {
        $order = $this->openTable();
        $this->launch($order);
        $payload = $this->closing();

        $this->post("/mesas/comandas/{$order->id}/fechar", $payload)->assertSessionHasNoErrors();
        $this->post("/mesas/comandas/{$order->id}/fechar", $payload)->assertSessionHasNoErrors();

        $this->assertSame(1, Sale::count());
        $this->assertSame('18.000', $this->soda->fresh()->stock_quantity);
    }

    public function test_comanda_vazia_nao_fecha_mas_pode_ser_cancelada(): void
    {
        $order = $this->openTable();

        $this->post("/mesas/comandas/{$order->id}/fechar", $this->closing(['expected_subtotal' => '0']))->assertSessionHasErrors('order');

        $this->post("/mesas/comandas/{$order->id}/cancelar", ['reason' => 'Clientes saíram'])->assertRedirect(route('tables.index'));

        $this->assertSame(TableOrder::CANCELLED, $order->fresh()->status);
        $this->assertNull($order->fresh()->open_table_id);
    }

    public function test_cancelar_item_exige_motivo_e_sai_da_conta(): void
    {
        $order = $this->openTable();
        $this->launch($order);
        $item = $order->items()->sole();

        $this->post("/mesas/comandas/{$order->id}/itens/{$item->id}/cancelar", [])->assertSessionHasErrors('reason');
        $this->post("/mesas/comandas/{$order->id}/itens/{$item->id}/cancelar", ['reason' => 'Pedido errado'])->assertSessionHasNoErrors();

        $this->assertSame('0.00', $order->subtotal());
        $this->assertSame($this->admin->name, $item->fresh()->cancelled_by);

        $this->post("/mesas/comandas/{$order->id}/itens/{$item->id}/cancelar", ['reason' => 'x'])->assertSessionHasErrors('reason');
    }

    public function test_item_cancelado_nao_entra_na_venda(): void
    {
        $order = $this->openTable();
        $this->launch($order);
        $this->launch($order, '1');
        $item = $order->items()->first();
        $this->post("/mesas/comandas/{$order->id}/itens/{$item->id}/cancelar", ['reason' => 'x']);

        $this->post("/mesas/comandas/{$order->id}/fechar", $this->closing([
            'expected_subtotal' => '10.00',
            'payments' => [['method' => 'pix', 'amount' => '10']],
        ]))->assertSessionHasNoErrors();

        $this->assertSame(1, Sale::sole()->items()->count());
        $this->assertSame('19.000', $this->soda->fresh()->stock_quantity);
    }

    public function test_item_de_outra_comanda_nao_e_cancelado_por_esta(): void
    {
        $order = $this->openTable();
        $this->launch($order);
        $item = $order->items()->sole();
        $other = DiningTable::factory()->create(['company_id' => $this->admin->company_id]);
        $this->post("/mesas/{$other->id}/abrir", ['people' => 1]);
        $otherOrder = TableOrder::where('open_table_id', $other->id)->sole();

        $this->post("/mesas/comandas/{$otherOrder->id}/itens/{$item->id}/cancelar", ['reason' => 'x'])->assertNotFound();
    }

    public function test_cozinha_recebe_so_o_que_ainda_nao_foi_enviado(): void
    {
        $order = $this->openTable();
        $this->launch($order, '1');

        $this->post("/mesas/comandas/{$order->id}/cozinha")->assertSessionHas('ticket_batch', 1);

        $this->launch($order, '2');

        $this->post("/mesas/comandas/{$order->id}/cozinha")->assertSessionHas('ticket_batch', 2);
        $this->post("/mesas/comandas/{$order->id}/cozinha")->assertSessionHas('error');

        $this->get("/mesas/comandas/{$order->id}/cozinha/2")->assertInertia(
            fn (Assert $page) => $page->has('items', 1)->where('items.0.quantity', '2.000')->where('table', 5)
        );
    }

    public function test_transferir_para_mesa_livre(): void
    {
        $order = $this->openTable();
        $free = DiningTable::factory()->create(['company_id' => $this->admin->company_id, 'number' => 9]);
        $busy = DiningTable::factory()->create(['company_id' => $this->admin->company_id, 'number' => 10]);
        $this->post("/mesas/{$busy->id}/abrir", ['people' => 1]);

        $this->post("/mesas/comandas/{$order->id}/transferir", ['dining_table_id' => $busy->id])->assertSessionHasErrors('dining_table_id');
        $this->post("/mesas/comandas/{$order->id}/transferir", ['dining_table_id' => $free->id])->assertSessionHas('success');

        $order->refresh();
        $this->assertSame($free->id, $order->dining_table_id);
        $this->assertSame($free->id, $order->open_table_id);
        $this->post("/mesas/{$this->table->id}/abrir", ['people' => 1])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('audit_logs', ['event' => 'table_transferred', 'user_id' => $this->admin->id]);
    }

    public function test_venda_de_mesa_com_ficha_baixa_insumos_e_pode_negativar(): void
    {
        $cheese = Product::factory()->create(['company_id' => $this->admin->company_id, 'name' => 'Queijo', 'unit' => 'KG', 'stock_quantity' => '0.1']);
        $pizza = Product::factory()->create(['company_id' => $this->admin->company_id, 'track_stock' => false, 'sale_price' => '40']);
        app(SyncRecipe::class)->handle($pizza, [['ingredient_id' => $cheese->id, 'quantity' => '0.25']]);

        $order = $this->openTable();
        $this->post("/mesas/comandas/{$order->id}/itens", [
            'client_uuid' => (string) Str::uuid(),
            'items' => [['product_id' => $pizza->id, 'quantity' => '1']],
        ]);

        $this->post("/mesas/comandas/{$order->id}/fechar", $this->closing([
            'expected_subtotal' => '40.00',
            'payments' => [['method' => 'pix', 'amount' => '40']],
        ]))->assertSessionHas('completed_sale.negative', ['Queijo (-0,15 KG)']);
    }

    public function test_permissoes_por_funcao(): void
    {
        $company = $this->admin->company;
        $order = $this->openTable();
        $this->launch($order);
        $item = $order->items()->sole();

        $this->actingAs($this->userWith([], $company))->get('/mesas')->assertForbidden();

        $waiter = $this->userWith(['tables.operate'], $company);
        $this->actingAs($waiter)->get('/mesas')->assertOk();
        $this->get("/mesas/comandas/{$order->id}")->assertOk();
        $this->launch($order, '1')->assertSessionHasNoErrors();
        $this->post("/mesas/comandas/{$order->id}/fechar", $this->closing())->assertForbidden();
        $this->post("/mesas/comandas/{$order->id}/itens/{$item->id}/cancelar", ['reason' => 'x'])->assertForbidden();
        $this->post("/mesas/comandas/{$order->id}/cancelar", ['reason' => 'x'])->assertForbidden();
        $this->post('/mesas', ['number' => 50, 'seats' => 4])->assertForbidden();
    }

    public function test_desconto_na_mesa_exige_permissao(): void
    {
        $operator = $this->userWith(['tables.operate', 'tables.close', 'cash.operate'], $this->admin->company);
        $this->openCash($operator);
        $order = $this->openTable();
        $this->launch($order);

        $this->actingAs($operator)->post("/mesas/comandas/{$order->id}/fechar", $this->closing([
            'discount' => '5',
            'payments' => [['method' => 'pix', 'amount' => '15']],
        ]))->assertSessionHasErrors('discount');
    }

    public function test_auditoria_de_lancamento_e_cancelamento_de_item(): void
    {
        $order = $this->openTable();
        $this->launch($order, '2');
        $item = $order->items()->sole();
        $this->post("/mesas/comandas/{$order->id}/itens/{$item->id}/cancelar", ['reason' => 'Erro do garçom']);

        $created = AuditLog::where('auditable_type', 'TableOrderItem')->where('event', 'created')->sole();
        $this->assertStringContainsString('2 UN de Refrigerante', $created->description);

        $cancelled = AuditLog::where('auditable_type', 'TableOrderItem')->where('event', 'updated')->sole();
        $this->assertSame('Erro do garçom', $cancelled->new_values['cancel_reason']);
        $this->assertSame($this->admin->id, $cancelled->user_id);
    }

    public function test_cadastro_de_mesas_em_lote_e_numero_unico(): void
    {
        $this->actingAs($this->admin)->post('/mesas', ['number' => 20, 'until' => 22, 'area' => 'Varanda', 'seats' => 2])->assertSessionHasNoErrors();

        $this->assertSame(3, DiningTable::where('area', 'Varanda')->count());

        $this->post('/mesas', ['number' => 21, 'seats' => 2])->assertSessionHasErrors('number');
    }

    public function test_mesa_ocupada_nao_inativa(): void
    {
        $this->openTable();

        $this->patch("/mesas/{$this->table->id}/status")->assertSessionHas('error');

        $this->assertTrue($this->table->fresh()->active);
    }

    public function test_mapa_mostra_ocupacao_e_total(): void
    {
        $order = $this->openTable();
        $this->launch($order);

        $this->get('/mesas')->assertInertia(
            fn (Assert $page) => $page->has('tables', 1)
                ->where('tables.0.order.id', $order->id)
                ->where('tables.0.order.people', 2)
                ->where('tables.0.order.total', fn ($total) => (float) $total === 20.0)
        );
    }

    public function test_pedir_a_conta_poe_a_mesa_em_fechamento_e_da_para_voltar(): void
    {
        $order = $this->openTable();
        $waiter = $this->userWith(['tables.operate'], $this->admin->company);

        $this->actingAs($waiter)->post("/mesas/comandas/{$order->id}/conta", ['requested' => true])->assertSessionHasNoErrors();

        $this->assertNotNull($order->fresh()->bill_requested_at);
        $this->get('/mesas')->assertInertia(fn (Assert $page) => $page->where('tables.0.order.closing', true));
        $this->get("/mesas/comandas/{$order->id}")->assertInertia(fn (Assert $page) => $page->where('order.closing', true));

        $this->post("/mesas/comandas/{$order->id}/conta", ['requested' => false])->assertSessionHasNoErrors();

        $this->assertNull($order->fresh()->bill_requested_at);
        $this->get('/mesas')->assertInertia(fn (Assert $page) => $page->where('tables.0.order.closing', false));
    }

    public function test_pedir_a_conta_de_novo_mantem_o_horario_do_primeiro_pedido(): void
    {
        $order = $this->openTable();
        $this->post("/mesas/comandas/{$order->id}/conta", ['requested' => true]);
        $first = $order->fresh()->bill_requested_at;

        $this->travel(5)->minutes();
        $this->post("/mesas/comandas/{$order->id}/conta", ['requested' => true]);

        $this->assertTrue($first->equalTo($order->fresh()->bill_requested_at));
    }

    public function test_conta_pedida_so_em_comanda_aberta_da_propria_empresa(): void
    {
        $order = $this->openTable();
        $this->post("/mesas/comandas/{$order->id}/cancelar", ['reason' => 'x']);

        $this->post("/mesas/comandas/{$order->id}/conta", ['requested' => true])->assertSessionHasErrors('order');
        $this->post("/mesas/comandas/{$order->id}/conta", [])->assertSessionHasErrors('requested');

        $this->actingAs($this->userWith([], $this->admin->company))->post("/mesas/comandas/{$order->id}/conta", ['requested' => true])->assertForbidden();
        $this->actingAs($this->productionAdminOf())->post("/mesas/comandas/{$order->id}/conta", ['requested' => true])->assertNotFound();
        $this->assertNull($order->fresh()->bill_requested_at);
    }

    public function test_item_cancelado_fica_fora_do_total_do_mapa(): void
    {
        $order = $this->openTable();
        $this->launch($order);
        $item = $order->items()->sole();
        $this->post("/mesas/comandas/{$order->id}/itens/{$item->id}/cancelar", ['reason' => 'x']);

        $this->get('/mesas')->assertInertia(fn (Assert $page) => $page->where('tables.0.order.total', fn ($total) => (float) $total === 0.0));
    }

    public function test_isolamento_entre_empresas(): void
    {
        $order = $this->openTable();
        $this->launch($order);
        $item = $order->items()->sole();
        $other = $this->productionAdminOf();
        $foreignProduct = Product::factory()->create(['company_id' => $other->company_id]);

        $this->actingAs($other);
        $this->get('/mesas')->assertInertia(fn (Assert $page) => $page->has('tables', 0));
        $this->get("/mesas/comandas/{$order->id}")->assertNotFound();
        $this->post("/mesas/comandas/{$order->id}/fechar", $this->closing())->assertNotFound();
        $this->post("/mesas/comandas/{$order->id}/itens/{$item->id}/cancelar", ['reason' => 'x'])->assertNotFound();
        $this->post("/mesas/{$this->table->id}/abrir", ['people' => 1])->assertNotFound();

        $this->actingAs($this->admin)->post("/mesas/comandas/{$order->id}/itens", [
            'client_uuid' => (string) Str::uuid(),
            'items' => [['product_id' => $foreignProduct->id, 'quantity' => '1']],
        ])->assertSessionHasErrors('items.0.product_id');
        $this->assertSame(1, TableOrderItem::count());
    }
}
