<?php

namespace Tests\Feature;

use App\Actions\ConsumeProduct;
use App\Actions\SyncRecipe;
use App\Models\AuditLog;
use App\Models\Product;
use App\Models\RecipeItem;
use App\Models\StockMovement;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class RecipeTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Product $cheese;

    private Product $flour;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->adminOf();
        $this->cheese = $this->product(['name' => 'Mussarela', 'unit' => 'KG', 'stock_quantity' => '10', 'cost_price' => '32']);
        $this->flour = $this->product(['name' => 'Farinha', 'unit' => 'KG', 'stock_quantity' => '1', 'cost_price' => '4']);
    }

    private function product(array $attributes = []): Product
    {
        return Product::factory()->create(['company_id' => $this->admin->company_id, ...$attributes]);
    }

    private function pizza(): Product
    {
        $pizza = $this->product(['name' => 'Pizza', 'track_stock' => false, 'sale_price' => '40']);

        app(SyncRecipe::class)->handle($pizza, [
            ['ingredient_id' => $this->cheese->id, 'quantity' => '0.250'],
            ['ingredient_id' => $this->flour->id, 'quantity' => '0.300'],
        ]);

        return $pizza->fresh();
    }

    private function payload(array $overrides = []): array
    {
        return [
            'name' => 'Pizza Nova',
            'unit' => 'UN',
            'sale_price' => '40',
            'track_stock' => false,
            'recipe' => [
                ['ingredient_id' => $this->cheese->id, 'quantity' => '0.25'],
                ['ingredient_id' => $this->flour->id, 'quantity' => '0.3'],
            ],
            ...$overrides,
        ];
    }

    public function test_cria_produto_com_ficha_tecnica(): void
    {
        $this->actingAs($this->admin)->post('/produtos', $this->payload())->assertRedirect(route('products.index'));

        $pizza = Product::where('name', 'Pizza Nova')->sole();

        $this->assertSame(2, $pizza->recipeItems()->count());
        $this->assertSame('9.2000', $pizza->load('recipeItems.ingredient')->recipeCost());
    }

    public function test_edita_troca_e_remove_insumos(): void
    {
        $pizza = $this->pizza();

        $this->actingAs($this->admin)->put("/produtos/{$pizza->id}", $this->payload([
            'name' => 'Pizza',
            'recipe' => [['ingredient_id' => $this->cheese->id, 'quantity' => '0.4']],
        ]))->assertSessionHasNoErrors();

        $item = $pizza->recipeItems()->sole();
        $this->assertSame($this->cheese->id, $item->ingredient_id);
        $this->assertSame('0.400', $item->quantity);

        $this->put("/produtos/{$pizza->id}", $this->payload(['name' => 'Pizza', 'recipe' => []]))->assertSessionHasNoErrors();
        $this->assertSame(0, $pizza->recipeItems()->count());
    }

    public function test_tela_de_edicao_traz_a_ficha(): void
    {
        $pizza = $this->pizza();

        $this->actingAs($this->admin)->get("/produtos/{$pizza->id}/editar")->assertInertia(
            fn (Assert $page) => $page->has('product.recipe', 2)
                ->where('product.recipe.0.name', 'Farinha')
                ->where('product.recipe.0.quantity', '0.300')
                ->where('product.recipe.0.unit', 'KG')
        );
    }

    public function test_lista_mostra_o_custo_da_ficha_sem_consultas_por_linha(): void
    {
        $this->pizza();

        $this->actingAs($this->admin)->get('/produtos?search=Pizza')->assertInertia(
            fn (Assert $page) => $page->where('products.data.0.cost_price', '9.2000')
                ->where('products.data.0.has_recipe', true)
        );
    }

    public function test_produto_que_controla_estoque_nao_tem_ficha(): void
    {
        $this->actingAs($this->admin)->post('/produtos', $this->payload(['track_stock' => true]))
            ->assertSessionHasErrors('recipe');

        $this->assertDatabaseMissing('products', ['name' => 'Pizza Nova']);
    }

    public function test_insumo_precisa_ser_da_empresa_e_controlar_estoque(): void
    {
        $foreign = Product::factory()->create();
        $untracked = $this->product(['track_stock' => false]);

        $this->actingAs($this->admin);

        foreach ([$foreign, $untracked] as $ingredient) {
            $this->post('/produtos', $this->payload(['recipe' => [
                ['ingredient_id' => $ingredient->id, 'quantity' => '1'],
            ]]))->assertSessionHasErrors('recipe.0.ingredient_id');
        }
    }

    public function test_quantidade_e_duplicidade(): void
    {
        $this->actingAs($this->admin);

        $this->post('/produtos', $this->payload(['recipe' => [
            ['ingredient_id' => $this->cheese->id, 'quantity' => '0'],
        ]]))->assertSessionHasErrors('recipe.0.quantity');

        $this->post('/produtos', $this->payload(['recipe' => [
            ['ingredient_id' => $this->cheese->id, 'quantity' => '1'],
            ['ingredient_id' => $this->cheese->id, 'quantity' => '2'],
        ]]))->assertSessionHasErrors('recipe.0.ingredient_id');
    }

    public function test_insumo_em_uso_nao_para_de_controlar_estoque(): void
    {
        $this->pizza();
        $empty = $this->product(['stock_quantity' => '0']);
        RecipeItem::query()->first()->update(['ingredient_id' => $empty->id]);

        $this->actingAs($this->admin)->put("/produtos/{$empty->id}", [
            'name' => $empty->name,
            'unit' => 'UN',
            'sale_price' => '1',
            'track_stock' => false,
            'recipe' => [],
        ])->assertSessionHasErrors('track_stock');
    }

    public function test_venda_baixa_os_insumos_proporcionalmente(): void
    {
        $pizza = $this->pizza();

        app(ConsumeProduct::class)->handle($pizza, '2');

        $this->assertSame('9.500', $this->cheese->fresh()->stock_quantity);
        $this->assertSame('0.400', $this->flour->fresh()->stock_quantity);
        $this->assertSame('0.000', $pizza->fresh()->stock_quantity);

        $movements = StockMovement::where('type', StockMovement::SALE_INGREDIENT)->get();
        $this->assertCount(2, $movements);
        $this->assertSame('Pizza', $movements->first()->notes);
    }

    public function test_falta_de_insumo_nao_impede_a_baixa_e_deixa_negativo(): void
    {
        $pizza = $this->pizza();

        app(ConsumeProduct::class)->handle($pizza, '4');

        $this->assertSame('9.000', $this->cheese->fresh()->stock_quantity);
        $this->assertSame('-0.200', $this->flour->fresh()->stock_quantity);
    }

    public function test_venda_de_produto_com_estoque_proprio_baixa_o_proprio_saldo(): void
    {
        $soda = $this->product(['stock_quantity' => '5']);

        app(ConsumeProduct::class)->handle($soda, '2');

        $this->assertSame('3.000', $soda->fresh()->stock_quantity);
        $this->assertSame(StockMovement::SALE, StockMovement::sole()->type);
    }

    public function test_venda_de_produto_sem_ficha_nem_estoque_nao_movimenta(): void
    {
        $service = $this->product(['track_stock' => false]);

        app(ConsumeProduct::class)->handle($service, '1');

        $this->assertSame(0, StockMovement::count());
    }

    public function test_custo_da_ficha_acompanha_o_custo_do_insumo(): void
    {
        $pizza = $this->pizza();

        $this->cheese->cost_price = '40';
        $this->cheese->save();

        $this->assertSame('11.2000', $pizza->fresh()->load('recipeItems.ingredient')->recipeCost());
    }

    public function test_porcoes_possiveis_seguem_o_insumo_que_acaba_primeiro(): void
    {
        $pizza = $this->pizza();

        $this->assertSame(3, $pizza->load('recipeItems.ingredient')->availablePortions());
    }

    public function test_alteracao_da_ficha_fica_em_um_unico_registro_legivel(): void
    {
        $pizza = $this->pizza();

        $this->actingAs($this->admin)->put("/produtos/{$pizza->id}", $this->payload([
            'name' => 'Pizza',
            'recipe' => [['ingredient_id' => $this->cheese->id, 'quantity' => '0.5']],
        ]));

        $log = AuditLog::where('auditable_id', $pizza->id)->where('auditable_type', 'Product')->latest('id')->first();

        $this->assertSame($this->admin->id, $log->user_id);
        $this->assertSame(['0,25 KG de Mussarela', '0,3 KG de Farinha'], array_reverse($log->old_values['recipe']));
        $this->assertSame(['0,5 KG de Mussarela'], $log->new_values['recipe']);
    }

    public function test_salvar_a_ficha_sem_mudanca_nao_gera_registro(): void
    {
        $pizza = $this->pizza();
        $before = AuditLog::count();

        app(SyncRecipe::class)->handle($pizza, [
            ['ingredient_id' => $this->flour->id, 'quantity' => '0.3'],
            ['ingredient_id' => $this->cheese->id, 'quantity' => '0.25'],
        ]);

        $this->assertSame($before, AuditLog::count());
    }

    public function test_busca_de_insumos_exige_permissao_de_produtos_ou_entradas(): void
    {
        $manager = $this->userWith(['products.manage'], $this->admin->company);

        $this->actingAs($manager)->getJson('/produtos/buscar?q=Mussarela')->assertJsonPath('0.id', $this->cheese->id);

        $this->actingAs($this->userWith(['products.view'], $this->admin->company))
            ->getJson('/produtos/buscar?q=Mussarela')->assertForbidden();
    }

    public function test_ficha_de_outra_empresa_nao_e_acessivel(): void
    {
        $pizza = $this->pizza();

        $this->actingAs($this->adminOf())->get("/produtos/{$pizza->id}/editar")->assertNotFound();
    }
}
