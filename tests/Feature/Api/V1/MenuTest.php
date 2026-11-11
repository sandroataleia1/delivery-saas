<?php

namespace Tests\Feature\Api\V1;

use App\Actions\SavePizzaSetup;
use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MenuTest extends TestCase
{
    use RefreshDatabase;

    private User $waiter;

    protected function setUp(): void
    {
        parent::setUp();

        $this->waiter = $this->userWith(['tables.operate'], $this->adminOf()->company);
    }

    /** @param  array<string, mixed>  $attributes */
    private function product(array $attributes = []): Product
    {
        return Product::factory()->create(['company_id' => $this->waiter->company_id, ...$attributes]);
    }

    public function test_lista_produtos_vendaveis_com_a_categoria(): void
    {
        $category = Category::factory()->create(['company_id' => $this->waiter->company_id, 'name' => 'Lanches']);
        $burger = $this->product(['name' => 'X-Burger', 'sale_price' => '28.00', 'category_id' => $category->id]);
        $this->product(['name' => 'Esgotado', 'sold_out' => true]);
        $this->product(['name' => 'Inativo', 'active' => false]);
        $this->product(['name' => 'Insumo', 'sellable' => false]);

        Sanctum::actingAs($this->waiter);

        $this->getJson('/api/v1/menu')
            ->assertOk()
            ->assertJsonCount(1, 'products')
            ->assertJsonPath('products.0.id', $burger->id)
            ->assertJsonPath('products.0.name', 'X-Burger')
            ->assertJsonPath('products.0.price', '28.00')
            ->assertJsonPath('products.0.from_price', '28.00')
            ->assertJsonPath('products.0.category_id', $category->id)
            ->assertJsonPath('products.0.composition', null)
            ->assertJsonPath('categories', [['id' => $category->id, 'name' => 'Lanches']]);
    }

    public function test_pizza_traz_tamanhos_e_sabores(): void
    {
        $pizza = $this->product(['name' => 'Pizza', 'sale_price' => '40', 'track_stock' => false]);
        app(SavePizzaSetup::class)->handle($pizza, [
            'pizza_pricing' => 'highest',
            'sizes' => [['name' => 'Grande', 'slices' => 8, 'max_flavors' => 2, 'active' => true, 'recipe' => []]],
            'flavors' => [['name' => 'Calabresa', 'active' => true, 'prices' => ['n0' => '49.90'], 'recipes' => []]],
        ]);

        Sanctum::actingAs($this->waiter);

        $this->getJson('/api/v1/menu')
            ->assertOk()
            ->assertJsonPath('products.0.from_price', '49.90')
            ->assertJsonPath('products.0.composition.pizza.sizes.0.name', 'Grande')
            ->assertJsonPath('products.0.composition.pizza.sizes.0.max_flavors', 2)
            ->assertJsonPath('products.0.composition.pizza.flavors.0.name', 'Calabresa');
    }

    public function test_nao_ve_cardapio_de_outra_empresa(): void
    {
        Product::factory()->create(['name' => 'De outra empresa']);
        Category::factory()->create(['name' => 'Outra']);

        Sanctum::actingAs($this->waiter);

        $this->getJson('/api/v1/menu')
            ->assertOk()
            ->assertJsonCount(0, 'products')
            ->assertJsonCount(0, 'categories');
    }

    public function test_precisa_de_permissao_de_mesas(): void
    {
        Sanctum::actingAs($this->userWith(['sales.create'], $this->waiter->company));

        $this->getJson('/api/v1/menu')->assertForbidden();
    }
}
