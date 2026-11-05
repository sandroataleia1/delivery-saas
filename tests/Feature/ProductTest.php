<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Models\Role;
use App\Models\StockMovement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ProductTest extends TestCase
{
    use RefreshDatabase;

    private function payload(array $overrides = []): array
    {
        return [
            'name' => 'Coca-Cola 2L',
            'unit' => 'UN',
            'sale_price' => '12.50',
            'track_stock' => true,
            'recipe' => [],
            ...$overrides,
        ];
    }

    public function test_permissoes(): void
    {
        $this->actingAs($this->userWith([]))->get('/produtos')->assertForbidden();

        $viewer = $this->userWith(['products.view']);
        $this->actingAs($viewer)->get('/produtos')->assertOk();
        $this->post('/produtos', $this->payload())->assertForbidden();
    }

    public function test_custo_e_saldo_ficam_ocultos_sem_permissao(): void
    {
        $viewer = $this->userWith(['products.view']);
        Product::factory()->create(['company_id' => $viewer->company_id]);

        $this->actingAs($viewer)->get('/produtos')->assertInertia(
            fn (Assert $page) => $page->where('products.data.0.cost_price', null)
                ->where('products.data.0.stock_quantity', null)
                ->where('products.data.0.sale_price', '10.00')
        );
    }

    public function test_cria_produto_com_saldo_inicial_e_movimentacao(): void
    {
        $admin = $this->adminOf();

        $this->actingAs($admin)->post('/produtos', $this->payload([
            'cost_price' => '6.5',
            'initial_stock' => '24',
        ]))->assertRedirect(route('products.index'));

        $product = Product::sole();
        $this->assertSame($admin->company_id, $product->company_id);
        $this->assertSame('24.000', $product->stock_quantity);
        $this->assertSame('6.5000', $product->cost_price);

        $movement = StockMovement::sole();
        $this->assertSame(StockMovement::INITIAL, $movement->type);
        $this->assertSame('24.000', $movement->balance_after);
        $this->assertSame($admin->id, $movement->user_id);
    }

    public function test_produto_sem_controle_de_estoque_ignora_saldo_inicial(): void
    {
        $this->actingAs($this->adminOf())->post('/produtos', $this->payload([
            'track_stock' => false,
            'initial_stock' => '10',
        ]));

        $this->assertSame('0.000', Product::sole()->stock_quantity);
        $this->assertSame(0, StockMovement::count());
    }

    public function test_formulario_nao_altera_saldo_nem_custo_de_produto_controlado(): void
    {
        $admin = $this->adminOf();
        $product = Product::factory()->create(['company_id' => $admin->company_id, 'stock_quantity' => '5', 'cost_price' => '3']);

        $this->actingAs($admin)->put("/produtos/{$product->id}", $this->payload([
            'stock_quantity' => '999',
            'cost_price' => '0.01',
            'initial_stock' => '999',
        ]))->assertSessionHasNoErrors();

        $this->assertSame('5.000', $product->fresh()->stock_quantity);
        $this->assertSame('3.0000', $product->fresh()->cost_price);
    }

    public function test_nao_para_de_controlar_estoque_com_saldo(): void
    {
        $admin = $this->adminOf();
        $product = Product::factory()->create(['company_id' => $admin->company_id, 'stock_quantity' => '5']);

        $this->actingAs($admin)->put("/produtos/{$product->id}", $this->payload(['track_stock' => false]))
            ->assertSessionHasErrors('track_stock');
    }

    public function test_codigos_sao_unicos_por_empresa(): void
    {
        $admin = $this->adminOf();
        Product::factory()->create(['company_id' => $admin->company_id, 'barcode' => '789', 'sku' => 'A1']);
        Product::factory()->create(['barcode' => '111', 'sku' => 'B1']);

        $this->actingAs($admin);
        $this->post('/produtos', $this->payload(['barcode' => '789']))->assertSessionHasErrors('barcode');
        $this->post('/produtos', $this->payload(['sku' => 'A1']))->assertSessionHasErrors('sku');
        $this->post('/produtos', $this->payload(['barcode' => '111', 'sku' => 'B1']))->assertSessionHasNoErrors();
    }

    public function test_isolamento_entre_empresas(): void
    {
        $admin = $this->adminOf();
        $foreign = Product::factory()->create();
        $foreignCategory = Category::factory()->create();

        $this->actingAs($admin);
        $this->get('/produtos')->assertInertia(fn (Assert $page) => $page->has('products.data', 0));
        $this->get("/produtos/{$foreign->id}/editar")->assertNotFound();
        $this->patch("/produtos/{$foreign->id}/status")->assertNotFound();
        $this->post('/produtos', $this->payload(['category_id' => $foreignCategory->id]))->assertSessionHasErrors('category_id');
        $this->getJson('/produtos/buscar?q='.urlencode($foreign->name))->assertExactJson([]);
    }

    public function test_busca_acha_por_codigo_de_barras_e_ignora_inativos(): void
    {
        $admin = $this->adminOf();
        $product = Product::factory()->create(['company_id' => $admin->company_id, 'barcode' => '7891234']);
        Product::factory()->create(['company_id' => $admin->company_id, 'name' => 'Inativo', 'active' => false]);

        $this->actingAs($admin);
        $this->getJson('/produtos/buscar?q=7891234')->assertJsonPath('0.id', $product->id);
        $this->getJson('/produtos/buscar?q=Inativo')->assertExactJson([]);
    }

    public function test_alteracao_de_preco_vai_para_a_auditoria(): void
    {
        $admin = $this->adminOf();
        $product = Product::factory()->create(['company_id' => $admin->company_id, 'sale_price' => '10']);

        $this->actingAs($admin)->put("/produtos/{$product->id}", $this->payload(['sale_price' => '15']));

        $this->assertDatabaseHas('audit_logs', [
            'event' => 'updated',
            'auditable_type' => 'Product',
            'auditable_id' => $product->id,
            'user_id' => $admin->id,
        ]);
    }

    public function test_categoria_com_produtos_nao_e_excluida(): void
    {
        $admin = $this->adminOf();
        $category = Category::factory()->create(['company_id' => $admin->company_id]);
        Product::factory()->create(['company_id' => $admin->company_id, 'category_id' => $category->id]);

        $this->actingAs($admin)->delete("/categorias/{$category->id}")->assertSessionHas('error');

        $this->assertDatabaseHas('categories', ['id' => $category->id]);
    }

    public function test_perfis_padrao_de_empresas_antigas_ganham_as_permissoes(): void
    {
        $migration = require database_path('migrations/2026_11_05_100006_grant_stock_permissions_to_default_roles.php');
        $role = Role::factory()->create(['name' => 'Caixa', 'permissions' => ['audit.view']]);

        $migration->up();

        $this->assertSame(['audit.view', 'products.view'], $role->fresh()->permissions);
    }
}
