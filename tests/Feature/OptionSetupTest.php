<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Category;
use App\Models\Option;
use App\Models\OptionGroup;
use App\Models\OptionPrice;
use App\Models\OptionRecipeItem;
use App\Models\PizzaSize;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OptionSetupTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Product $cheese;

    private Product $flour;

    private Product $pepperoni;

    private Product $pizza;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->adminOf();
        $this->cheese = $this->product(['name' => 'Queijo', 'unit' => 'KG']);
        $this->flour = $this->product(['name' => 'Farinha', 'unit' => 'KG']);
        $this->pepperoni = $this->product(['name' => 'Calabresa', 'unit' => 'KG']);
        $this->pizza = $this->product(['name' => 'Pizza', 'track_stock' => false, 'sale_price' => '40']);
    }

    private function product(array $attributes = []): Product
    {
        return Product::factory()->create(['company_id' => $this->admin->company_id, ...$attributes]);
    }

    private function groupPayload(array $overrides = []): array
    {
        return [
            'name' => 'Adicionais',
            'min_choices' => 0,
            'max_choices' => 3,
            'allows_quantity' => true,
            'active' => true,
            'product_ids' => [$this->pizza->id],
            'category_ids' => [],
            'options' => [
                ['name' => 'Bacon', 'price' => '4.50', 'active' => true, 'recipe' => [
                    ['ingredient_id' => $this->cheese->id, 'quantity' => '0.030'],
                ]],
                ['name' => 'Ovo', 'price' => '2.00', 'active' => true, 'recipe' => []],
            ],
            ...$overrides,
        ];
    }

    private function pizzaPayload(array $overrides = []): array
    {
        return [
            'pizza_pricing' => 'highest',
            'sizes' => [
                ['name' => 'Broto', 'slices' => 4, 'max_flavors' => 1, 'active' => true, 'recipe' => []],
                ['name' => 'Grande', 'slices' => 8, 'max_flavors' => 2, 'active' => true, 'recipe' => [
                    ['ingredient_id' => $this->flour->id, 'quantity' => '0.300'],
                ]],
            ],
            'flavors' => [
                [
                    'name' => 'Calabresa', 'active' => true,
                    'prices' => ['n0' => '39.90', 'n1' => '49.90'],
                    'recipes' => ['n1' => [['ingredient_id' => $this->pepperoni->id, 'quantity' => '0.150']]],
                ],
                [
                    'name' => 'Quatro queijos', 'active' => true,
                    'prices' => ['n0' => '44.90', 'n1' => '59.90'],
                    'recipes' => [],
                ],
            ],
            ...$overrides,
        ];
    }

    public function test_admin_creates_extra_group_with_options_recipe_and_products(): void
    {
        $this->actingAs($this->admin)
            ->post(route('options.store'), $this->groupPayload())
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $group = OptionGroup::sole();
        $this->assertSame('extra', $group->kind);
        $this->assertSame(2, Option::count());
        $this->assertSame(1, OptionRecipeItem::count());
        $this->assertSame('0.030', OptionRecipeItem::sole()->quantity);
        $this->assertDatabaseHas('product_option_group', ['product_id' => $this->pizza->id, 'option_group_id' => $group->id, 'company_id' => $this->admin->company_id]);
        $this->assertTrue($this->pizza->optionGroups()->whereKey($group->id)->exists());
        $this->assertTrue(AuditLog::where('auditable_type', 'OptionGroup')->where('event', 'created')->exists());
    }

    public function test_group_rejects_min_greater_than_max_and_foreign_ingredient(): void
    {
        $foreign = Product::factory()->create();

        $payload = $this->groupPayload(['min_choices' => 3, 'max_choices' => 1]);
        $payload['options'][0]['recipe'][0]['ingredient_id'] = $foreign->id;

        $this->actingAs($this->admin)
            ->post(route('options.store'), $payload)
            ->assertSessionHasErrors(['max_choices', 'options.0.recipe.0.ingredient_id']);

        $this->assertSame(0, OptionGroup::count());
    }

    public function test_admin_updates_group_keeping_option_ids_and_deactivating_omitted(): void
    {
        $this->actingAs($this->admin)->post(route('options.store'), $this->groupPayload());
        $group = OptionGroup::sole();
        [$bacon, $egg] = Option::orderBy('id')->get();

        $payload = $this->groupPayload(['product_ids' => []]);
        $payload['options'] = [['id' => $bacon->id, 'name' => 'Bacon crocante', 'price' => '5.00', 'active' => true, 'recipe' => []]];

        $this->actingAs($this->admin)
            ->put(route('options.update', $group), $payload)
            ->assertSessionHasNoErrors();

        $this->assertSame('Bacon crocante', $bacon->fresh()->name);
        $this->assertFalse($egg->fresh()->active);
        $this->assertSame(0, OptionRecipeItem::count());
        $this->assertSame(0, $this->pizza->optionGroups()->count());
    }

    public function test_admin_configures_pizza_sizes_flavors_prices_and_recipes(): void
    {
        $this->actingAs($this->admin)
            ->put(route('products.pizza.update', $this->pizza), $this->pizzaPayload())
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $this->assertSame('highest', $this->pizza->fresh()->pizza_pricing);
        $this->assertTrue($this->pizza->fresh()->isPizza());

        $sizes = $this->pizza->pizzaSizes()->with('recipeItems')->get();
        $this->assertSame(['Broto', 'Grande'], $sizes->pluck('name')->all());
        $this->assertSame([1, 2], $sizes->pluck('max_flavors')->all());

        $group = $this->pizza->optionGroups()->where('kind', 'flavor')->with('options.recipeItems')->sole();
        $this->assertSame(2, $group->options->count());
        $this->assertSame(4, OptionPrice::count());

        $calabresa = $group->options->firstWhere('name', 'Calabresa');
        $grande = $sizes->firstWhere('name', 'Grande');
        $this->assertSame('49.90', OptionPrice::where('option_id', $calabresa->id)->where('pizza_size_id', $grande->id)->value('price'));

        $this->assertSame('0.300', $grande->recipeItems->sole()->quantity);
        $this->assertNull($grande->recipeItems->sole()->option_id);
        $flavorRecipe = $calabresa->recipeItems->sole();
        $this->assertSame($grande->id, $flavorRecipe->pizza_size_id);
        $this->assertSame('0.150', $flavorRecipe->quantity);
    }

    public function test_pizza_update_keeps_ids_and_drops_nothing_when_resubmitted(): void
    {
        $this->actingAs($this->admin)->put(route('products.pizza.update', $this->pizza), $this->pizzaPayload());
        $sizes = $this->pizza->pizzaSizes;
        $flavors = $this->pizza->optionGroups()->where('kind', 'flavor')->sole()->options;

        $payload = $this->pizzaPayload();
        $payload['sizes'][0]['id'] = $sizes[0]->id;
        $payload['sizes'][1]['id'] = $sizes[1]->id;
        $payload['flavors'][0]['id'] = $flavors[0]->id;
        $payload['flavors'][0]['prices'] = [$sizes[0]->id => '41.00', $sizes[1]->id => '51.00'];
        $payload['flavors'][0]['recipes'] = [];
        unset($payload['flavors'][1]);

        $this->actingAs($this->admin)
            ->put(route('products.pizza.update', $this->pizza), $payload)
            ->assertSessionHasNoErrors();

        $this->assertSame(2, PizzaSize::count());
        $this->assertSame(1, Option::where('active', true)->count());
        $this->assertSame('41.00', OptionPrice::where('option_id', $flavors[0]->id)->where('pizza_size_id', $sizes[0]->id)->value('price'));
        $this->assertSame(0, $flavors[0]->recipeItems()->count());
    }

    public function test_flavor_needs_price_for_every_active_size(): void
    {
        $payload = $this->pizzaPayload();
        unset($payload['flavors'][0]['prices']['n0']);

        $this->actingAs($this->admin)
            ->put(route('products.pizza.update', $this->pizza), $payload)
            ->assertSessionHasErrors('flavors.0.prices');

        $this->assertSame(0, PizzaSize::count());
        $this->assertNull($this->pizza->fresh()->pizza_pricing);
    }

    public function test_pause_option_requires_products_manage(): void
    {
        $this->actingAs($this->admin)->post(route('options.store'), $this->groupPayload());
        $option = Option::orderBy('id')->first();

        $viewer = $this->userWith(['products.view'], $this->admin->company);
        $this->actingAs($viewer)->patch(route('options.pause', $option), ['paused' => true])->assertForbidden();
        $this->assertFalse($option->fresh()->paused);

        $this->actingAs($this->admin)->patch(route('options.pause', $option), ['paused' => true])->assertRedirect();
        $this->assertTrue($option->fresh()->paused);
    }

    public function test_option_setup_is_isolated_by_company(): void
    {
        $this->actingAs($this->admin)->post(route('options.store'), $this->groupPayload());
        $group = OptionGroup::sole();
        $option = Option::first();

        $other = $this->adminOf();
        $this->actingAs($other)->put(route('options.update', $group), $this->groupPayload(['product_ids' => []]))->assertNotFound();
        $this->actingAs($other)->patch(route('options.pause', $option), ['paused' => true])->assertNotFound();
        $this->actingAs($other)->put(route('products.pizza.update', $this->pizza), $this->pizzaPayload())->assertNotFound();
    }

    public function test_group_is_linked_to_categories_and_rejects_foreign_category(): void
    {
        $snacks = Category::factory()->create(['company_id' => $this->admin->company_id]);
        $foreign = Category::factory()->create();

        $this->actingAs($this->admin)
            ->post(route('options.store'), $this->groupPayload(['category_ids' => [$foreign->id]]))
            ->assertSessionHasErrors('category_ids.0');

        $this->post(route('options.store'), $this->groupPayload(['category_ids' => [$snacks->id]]))->assertSessionHasNoErrors();
        $group = OptionGroup::sole();
        $this->assertDatabaseHas('category_option_group', ['category_id' => $snacks->id, 'option_group_id' => $group->id, 'company_id' => $this->admin->company_id]);

        $this->put(route('options.update', $group), $this->groupPayload(['category_ids' => []]))->assertSessionHasNoErrors();
        $this->assertDatabaseMissing('category_option_group', ['option_group_id' => $group->id]);
    }

    public function test_category_linked_to_group_can_still_be_deleted(): void
    {
        $snacks = Category::factory()->create(['company_id' => $this->admin->company_id]);
        $this->actingAs($this->admin)->post(route('options.store'), $this->groupPayload(['category_ids' => [$snacks->id]]));

        $this->delete(route('categories.destroy', $snacks))->assertSessionHasNoErrors();

        $this->assertModelMissing($snacks);
        $this->assertDatabaseCount('category_option_group', 0);
    }

    public function test_extra_group_rejects_foreign_product(): void
    {
        $foreign = Product::factory()->create();

        $this->actingAs($this->admin)
            ->post(route('options.store'), $this->groupPayload(['product_ids' => [$foreign->id]]))
            ->assertSessionHasErrors('product_ids.0');
    }

    public function test_same_ingredient_is_valid_across_different_recipes(): void
    {
        $payload = $this->pizzaPayload();
        $payload['sizes'][0]['recipe'] = [['ingredient_id' => $this->flour->id, 'quantity' => '0.200']];
        $payload['sizes'][1]['recipe'] = [['ingredient_id' => $this->flour->id, 'quantity' => '0.300']];
        $payload['flavors'][0]['recipes'] = [
            'n0' => [['ingredient_id' => $this->pepperoni->id, 'quantity' => '0.100']],
            'n1' => [['ingredient_id' => $this->pepperoni->id, 'quantity' => '0.150']],
        ];

        $this->actingAs($this->admin)
            ->put(route('products.pizza.update', $this->pizza), $payload)
            ->assertSessionHasNoErrors();

        $this->assertSame(4, OptionRecipeItem::count());

        $group = $this->groupPayload();
        $group['options'][1]['recipe'] = [['ingredient_id' => $this->cheese->id, 'quantity' => '0.010']];

        $this->actingAs($this->admin)->post(route('options.store'), $group)->assertSessionHasNoErrors();
        $this->assertSame(2, OptionRecipeItem::where('ingredient_id', $this->cheese->id)->count());
    }

    public function test_repeated_ingredient_inside_one_recipe_is_rejected(): void
    {
        $item = ['ingredient_id' => $this->flour->id, 'quantity' => '0.100'];

        $payload = $this->pizzaPayload();
        $payload['sizes'][1]['recipe'] = [$item, $item];
        $this->actingAs($this->admin)
            ->put(route('products.pizza.update', $this->pizza), $payload)
            ->assertSessionHasErrors('sizes.1.recipe.1.ingredient_id');

        $payload = $this->pizzaPayload();
        $payload['flavors'][0]['recipes'] = ['n1' => [$item, $item]];
        $this->actingAs($this->admin)
            ->put(route('products.pizza.update', $this->pizza), $payload)
            ->assertSessionHasErrors('flavors.0.recipes.n1.1.ingredient_id');

        $group = $this->groupPayload();
        $group['options'][0]['recipe'] = [$item, $item];
        $this->actingAs($this->admin)
            ->post(route('options.store'), $group)
            ->assertSessionHasErrors('options.0.recipe.1.ingredient_id');
    }

    public function test_group_position_is_order_within_the_product_and_stable_on_resave(): void
    {
        $this->actingAs($this->admin)->put(route('products.pizza.update', $this->pizza), $this->pizzaPayload());
        $this->actingAs($this->admin)->post(route('options.store'), $this->groupPayload(['name' => 'Adicionais']));
        $this->actingAs($this->admin)->post(route('options.store'), $this->groupPayload(['name' => 'Bordas']));

        $order = fn () => $this->pizza->optionGroups()->get()->pluck('name')->all();
        $this->assertSame(['Sabores de Pizza', 'Adicionais', 'Bordas'], $order());

        $first = OptionGroup::where('name', 'Adicionais')->sole();
        $this->actingAs($this->admin)->put(route('options.update', $first), $this->groupPayload(['name' => 'Adicionais', 'options' => [
            ['id' => $first->options()->first()->id, 'name' => 'Bacon', 'price' => '5.00', 'active' => true, 'recipe' => []],
        ]]))->assertSessionHasNoErrors();

        $this->assertSame(['Sabores de Pizza', 'Adicionais', 'Bordas'], $order());
    }

    public function test_blank_size_price_removes_the_override(): void
    {
        $this->actingAs($this->admin)->post(route('options.store'), $this->groupPayload());
        $bacon = Option::where('name', 'Bacon')->sole();

        $payload = $this->pizzaPayload();
        $payload['size_prices'] = [$bacon->id => ['n0' => '5.00', 'n1' => '7.00']];
        $this->actingAs($this->admin)->put(route('products.pizza.update', $this->pizza), $payload)->assertSessionHasNoErrors();
        $this->assertSame(2, OptionPrice::where('option_id', $bacon->id)->count());

        $sizes = $this->pizza->pizzaSizes()->get();
        $payload = $this->pizzaPayload();
        $payload['sizes'][0]['id'] = $sizes[0]->id;
        $payload['sizes'][1]['id'] = $sizes[1]->id;
        $flavors = $this->pizza->optionGroups()->where('kind', 'flavor')->sole()->options;
        $payload['flavors'][0]['id'] = $flavors[0]->id;
        $payload['flavors'][1]['id'] = $flavors[1]->id;
        $payload['flavors'][0]['prices'] = [$sizes[0]->id => '39.90', $sizes[1]->id => '49.90'];
        $payload['flavors'][1]['prices'] = [$sizes[0]->id => '44.90', $sizes[1]->id => '59.90'];
        $payload['flavors'][0]['recipes'] = [];
        $payload['size_prices'] = [$bacon->id => [$sizes[0]->id => '', $sizes[1]->id => '7.00']];

        $this->actingAs($this->admin)->put(route('products.pizza.update', $this->pizza), $payload)->assertSessionHasNoErrors();

        $this->assertSame([$sizes[1]->id], OptionPrice::where('option_id', $bacon->id)->pluck('pizza_size_id')->all());
    }

    public function test_option_pages_render_for_manager(): void
    {
        $this->actingAs($this->admin)->post(route('options.store'), $this->groupPayload());

        $this->actingAs($this->admin)->get(route('options.index'))->assertOk();
        $this->actingAs($this->admin)->get(route('products.pizza.edit', $this->pizza))->assertOk();
    }
}
