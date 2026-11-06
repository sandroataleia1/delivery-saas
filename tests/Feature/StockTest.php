<?php

namespace Tests\Feature;

use App\Actions\MoveStock;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\Product;
use App\Models\StockEntry;
use App\Models\StockMovement;
use App\Models\Supplier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use LogicException;
use Tests\TestCase;

class StockTest extends TestCase
{
    use RefreshDatabase;

    private function product($user, array $attributes = []): Product
    {
        return Product::factory()->create(['company_id' => $user->company_id, ...$attributes]);
    }

    private function entry(array $items, array $overrides = []): array
    {
        return ['entry_date' => today()->format('Y-m-d'), 'items' => $items, ...$overrides];
    }

    public function test_entrada_soma_saldo_e_recalcula_custo_medio(): void
    {
        $admin = $this->adminOf();
        $product = $this->product($admin, ['stock_quantity' => '10', 'cost_price' => '5']);

        $this->actingAs($admin)->post('/entradas', $this->entry([
            ['product_id' => $product->id, 'quantity' => '10', 'unit_cost' => '7'],
        ]))->assertRedirect();

        $product->refresh();
        $this->assertSame('20.000', $product->stock_quantity);
        $this->assertSame('6.0000', $product->cost_price);

        $entry = StockEntry::sole();
        $this->assertSame('70.0000', $entry->total);
        $this->assertSame($admin->name, $entry->user_name);
        $this->assertSame($entry->id, StockMovement::sole()->stock_entry_id);
    }

    public function test_quantidade_fracionada_sem_erro_de_arredondamento(): void
    {
        $admin = $this->adminOf();
        $product = $this->product($admin, ['unit' => 'KG']);

        $this->actingAs($admin);

        foreach (range(1, 3) as $repeat) {
            $this->post('/entradas', $this->entry([['product_id' => $product->id, 'quantity' => '0.1', 'unit_cost' => '10']]));
        }

        $this->assertSame('0.300', $product->fresh()->stock_quantity);
    }

    public function test_entrada_exige_itens_validos(): void
    {
        $admin = $this->adminOf();
        $product = $this->product($admin);
        $untracked = $this->product($admin, ['track_stock' => false]);

        $this->actingAs($admin);
        $this->post('/entradas', $this->entry([]))->assertSessionHasErrors('items');
        $this->post('/entradas', $this->entry([['product_id' => $product->id, 'quantity' => '0', 'unit_cost' => '1']]))
            ->assertSessionHasErrors('items.0.quantity');
        $this->post('/entradas', $this->entry([['product_id' => $untracked->id, 'quantity' => '1', 'unit_cost' => '1']]))
            ->assertSessionHasErrors('items.0.product_id');
        $this->post('/entradas', $this->entry([
            ['product_id' => $product->id, 'quantity' => '1', 'unit_cost' => '1'],
            ['product_id' => $product->id, 'quantity' => '1', 'unit_cost' => '1'],
        ]))->assertSessionHasErrors('items.0.product_id');
        $this->post('/entradas', $this->entry(
            [['product_id' => $product->id, 'quantity' => '1', 'unit_cost' => '1']],
            ['entry_date' => today()->addDay()->format('Y-m-d')],
        ))->assertSessionHasErrors('entry_date');

        $this->assertSame(0, StockEntry::count());
    }

    public function test_entrada_nao_aceita_produto_ou_fornecedor_de_outra_empresa(): void
    {
        $admin = $this->adminOf();
        $product = $this->product($admin);
        $foreign = Product::factory()->create();
        $supplier = Supplier::factory()->create();

        $this->actingAs($admin);
        $this->post('/entradas', $this->entry([['product_id' => $foreign->id, 'quantity' => '1', 'unit_cost' => '1']]))
            ->assertSessionHasErrors('items.0.product_id');
        $this->post('/entradas', $this->entry(
            [['product_id' => $product->id, 'quantity' => '1', 'unit_cost' => '1']],
            ['supplier_id' => $supplier->id],
        ))->assertSessionHasErrors('supplier_id');

        $this->assertSame('0.000', $foreign->fresh()->stock_quantity);
    }

    public function test_cancelar_entrada_reverte_saldo_e_custo(): void
    {
        $admin = $this->adminOf();
        $product = $this->product($admin, ['stock_quantity' => '10', 'cost_price' => '5']);

        $this->actingAs($admin)->post('/entradas', $this->entry([
            ['product_id' => $product->id, 'quantity' => '10', 'unit_cost' => '7'],
        ]));
        $entry = StockEntry::sole();

        $this->post("/entradas/{$entry->id}/cancelar", ['reason' => 'Nota errada'])->assertSessionHas('success');

        $product->refresh();
        $entry->refresh();
        $this->assertSame('10.000', $product->stock_quantity);
        $this->assertSame('5.0000', $product->cost_price);
        $this->assertTrue($entry->isCancelled());
        $this->assertSame('Nota errada', $entry->cancel_reason);
        $this->assertSame($admin->name, $entry->cancelled_by);
        $this->assertSame(2, StockMovement::count());
        $this->assertSame('-10.000', StockMovement::latest('id')->first()->quantity);
    }

    public function test_cancelamento_exige_motivo_e_nao_repete(): void
    {
        $admin = $this->adminOf();
        $product = $this->product($admin);

        $this->actingAs($admin)->post('/entradas', $this->entry([['product_id' => $product->id, 'quantity' => '2', 'unit_cost' => '1']]));
        $entry = StockEntry::sole();

        $this->post("/entradas/{$entry->id}/cancelar", [])->assertSessionHasErrors('reason');
        $this->post("/entradas/{$entry->id}/cancelar", ['reason' => 'x']);
        $this->post("/entradas/{$entry->id}/cancelar", ['reason' => 'x'])->assertSessionHasErrors('stock');

        $this->assertSame('0.000', $product->fresh()->stock_quantity);
    }

    public function test_nao_cancela_entrada_cuja_mercadoria_ja_saiu_e_nada_e_desfeito(): void
    {
        $admin = $this->adminOf();
        $first = $this->product($admin);
        $second = $this->product($admin);

        $this->actingAs($admin)->post('/entradas', $this->entry([
            ['product_id' => $first->id, 'quantity' => '5', 'unit_cost' => '1'],
            ['product_id' => $second->id, 'quantity' => '5', 'unit_cost' => '1'],
        ]));
        $entry = StockEntry::sole();

        app(MoveStock::class)->handle($second, StockMovement::LOSS, '-3');

        $this->post("/entradas/{$entry->id}/cancelar", ['reason' => 'x'])->assertSessionHasErrors('stock');

        $this->assertSame('5.000', $first->fresh()->stock_quantity);
        $this->assertSame('2.000', $second->fresh()->stock_quantity);
        $this->assertFalse($entry->fresh()->isCancelled());
    }

    public function test_ajuste_manual_exige_motivo_e_nao_deixa_saldo_negativo(): void
    {
        $admin = $this->adminOf();
        $product = $this->product($admin, ['stock_quantity' => '5']);

        $this->actingAs($admin);
        $this->post("/estoque/{$product->id}/ajuste", ['type' => 'loss', 'quantity' => '1'])->assertSessionHasErrors('notes');
        $this->post("/estoque/{$product->id}/ajuste", ['type' => 'loss', 'quantity' => '6', 'notes' => 'x'])
            ->assertSessionHasErrors(['stock' => "Saldo insuficiente de {$product->name}: há 5 UN."]);
        $this->post("/estoque/{$product->id}/ajuste", ['type' => 'entry', 'quantity' => '1', 'notes' => 'x'])->assertSessionHasErrors('type');
        $this->post("/estoque/{$product->id}/ajuste", ['type' => 'loss', 'quantity' => '2', 'notes' => 'Quebrou'])->assertSessionHas('success');

        $this->assertSame('3.000', $product->fresh()->stock_quantity);
        $movement = StockMovement::sole();
        $this->assertSame('-2.000', $movement->quantity);
        $this->assertSame('Quebrou', $movement->notes);
    }

    public function test_acoes_de_estoque_exigem_permissao(): void
    {
        $user = $this->userWith(['stock.view']);
        $product = $this->product($user, ['stock_quantity' => '5']);

        $this->actingAs($user);
        $this->get('/estoque')->assertOk();
        $this->get('/estoque/movimentacoes')->assertOk();
        $this->post("/estoque/{$product->id}/ajuste", ['type' => 'loss', 'quantity' => '1', 'notes' => 'x'])->assertForbidden();
        $this->get('/entradas/nova')->assertForbidden();
        $this->post('/entradas', [])->assertForbidden();
        $this->get('/produtos/buscar?q=x')->assertForbidden();
    }

    public function test_movimentacao_nao_pode_ser_alterada_nem_excluida(): void
    {
        $product = Product::factory()->create();
        $movement = app(MoveStock::class)->handle($product, StockMovement::ADJUSTMENT_IN, '1');

        try {
            $movement->notes = 'adulterado';
            $movement->save();
            $this->fail('Deveria ter bloqueado a alteração.');
        } catch (LogicException) {
        }

        $this->expectException(LogicException::class);
        $movement->delete();
    }

    public function test_saldo_negativo_e_bloqueado_na_acao(): void
    {
        $product = Product::factory()->create(['stock_quantity' => '1']);

        $this->expectException(ValidationException::class);

        app(MoveStock::class)->handle($product, StockMovement::LOSS, '-2');
    }

    public function test_auditoria_registra_a_movimentacao_em_linguagem_clara(): void
    {
        $admin = $this->adminOf();
        $product = $this->product($admin, ['name' => 'Farinha', 'unit' => 'KG', 'stock_quantity' => '5']);

        $this->actingAs($admin)->post("/estoque/{$product->id}/ajuste", ['type' => 'loss', 'quantity' => '2', 'notes' => 'Vencida']);

        $log = AuditLog::where('auditable_type', 'StockMovement')->sole();

        $this->assertSame($admin->id, $log->user_id);
        $this->assertStringContainsString('Perda de 2 KG em Farinha', $log->description);
        $this->assertSame('Perda', $log->new_values['type']);
        $this->assertSame('-2', $log->new_values['quantity']);
        $this->assertSame('Vencida', $log->new_values['notes']);
    }

    public function test_cancelamento_de_entrada_fica_na_auditoria_com_antes_e_depois(): void
    {
        $admin = $this->adminOf();
        $product = $this->product($admin);

        $this->actingAs($admin)->post('/entradas', $this->entry([['product_id' => $product->id, 'quantity' => '1', 'unit_cost' => '1']]));
        $entry = StockEntry::sole();
        $this->post("/entradas/{$entry->id}/cancelar", ['reason' => 'Duplicada']);

        $log = AuditLog::where('auditable_type', 'StockEntry')->where('event', 'updated')->sole();

        $this->assertSame('Confirmada', $log->old_values['status']);
        $this->assertSame('Cancelada', $log->new_values['status']);
        $this->assertSame('Duplicada', $log->new_values['cancel_reason']);
    }

    public function test_isolamento_de_estoque_e_entradas(): void
    {
        $admin = $this->adminOf();
        $otherAdmin = $this->adminOf();
        $foreign = $this->product($otherAdmin, ['stock_quantity' => '5']);

        $this->actingAs($otherAdmin)->post('/entradas', $this->entry([['product_id' => $foreign->id, 'quantity' => '1', 'unit_cost' => '1']]));
        $entry = StockEntry::withoutGlobalScopes()->sole();

        $this->actingAs($admin);
        $this->get('/estoque')->assertInertia(fn (Assert $page) => $page->has('products.data', 0)->where('summary.products', 0));
        $this->get('/estoque/movimentacoes')->assertInertia(fn (Assert $page) => $page->has('movements.data', 0));
        $this->get('/entradas')->assertInertia(fn (Assert $page) => $page->has('entries.data', 0));
        $this->get("/entradas/{$entry->id}")->assertNotFound();
        $this->post("/entradas/{$entry->id}/cancelar", ['reason' => 'x'])->assertNotFound();
        $this->post("/estoque/{$foreign->id}/ajuste", ['type' => 'loss', 'quantity' => '1', 'notes' => 'x'])->assertNotFound();
    }

    public function test_filtro_de_estoque_baixo_e_valor_total(): void
    {
        $admin = $this->adminOf();
        $this->product($admin, ['name' => 'Baixo', 'stock_quantity' => '2', 'min_stock' => '5', 'cost_price' => '10']);
        $this->product($admin, ['name' => 'Normal', 'stock_quantity' => '9', 'min_stock' => '5', 'cost_price' => '1']);
        $this->product($admin, ['name' => 'Sem mínimo', 'stock_quantity' => '0', 'min_stock' => '0']);

        $this->actingAs($admin);
        $this->get('/estoque?low=1')->assertInertia(
            fn (Assert $page) => $page->has('products.data', 1)
                ->where('products.data.0.name', 'Baixo')
                ->where('products.data.0.is_low', true)
                ->where('summary.low', 1)
                ->where('summary.products', 3)
        );
    }

    public function test_fornecedor_com_cnpj_invalido_e_recusado_e_isolado(): void
    {
        $admin = $this->adminOf();
        $foreign = Supplier::factory()->create(['company_id' => Company::factory()->create()->id]);

        $this->actingAs($admin);
        $this->post('/fornecedores', ['name' => 'X', 'document' => '11222333000199'])->assertSessionHasErrors('document');
        $this->post('/fornecedores', ['name' => 'Distribuidora', 'document' => '11.222.333/0001-81'])->assertSessionHasNoErrors();
        $this->get("/fornecedores/{$foreign->id}/editar")->assertNotFound();

        $this->assertDatabaseHas('suppliers', ['name' => 'Distribuidora', 'document' => '11222333000181', 'company_id' => $admin->company_id]);
    }
}
