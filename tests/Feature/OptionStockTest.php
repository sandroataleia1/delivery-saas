<?php

namespace Tests\Feature;

use App\Actions\OpenTable;
use App\Actions\SavePizzaSetup;
use App\Actions\SyncOptionRecipe;
use App\Models\Customer;
use App\Models\DeliveryOrder;
use App\Models\DiningTable;
use App\Models\Option;
use App\Models\OptionGroup;
use App\Models\Product;
use App\Models\ProductionWork;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\StockMovement;
use App\Models\TableOrder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class OptionStockTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Product $pizza;

    private Product $flour;

    private Product $pepperoni;

    private Product $cheese;

    private Product $baconStock;

    private Option $bacon;

    private int $grande;

    /** @var array<int, int> */
    private array $flavorIds = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->productionAdminOf();
        $this->actingAs($this->admin);
        $companyId = $this->admin->company_id;

        $ingredient = fn (string $name, string $cost) => Product::factory()->create(['company_id' => $companyId, 'name' => $name, 'cost_price' => $cost, 'stock_quantity' => '100', 'sale_price' => '1']);
        $this->flour = $ingredient('Farinha', '10');
        $this->pepperoni = $ingredient('Calabresa fatiada', '20');
        $this->cheese = $ingredient('Queijo', '40');
        $this->baconStock = $ingredient('Bacon fatiado', '50');

        $this->pizza = Product::factory()->create(['company_id' => $companyId, 'name' => 'Pizza', 'sale_price' => '40', 'track_stock' => false]);
        app(SavePizzaSetup::class)->handle($this->pizza, [
            'pizza_pricing' => 'highest',
            'sizes' => [
                ['name' => 'Broto', 'slices' => 4, 'max_flavors' => 1, 'active' => true, 'recipe' => []],
                ['name' => 'Grande', 'slices' => 8, 'max_flavors' => 2, 'active' => true, 'recipe' => []],
            ],
            'flavors' => [
                ['name' => 'Calabresa', 'active' => true, 'prices' => ['n0' => '39.90', 'n1' => '49.90'], 'recipes' => []],
                ['name' => 'Quatro queijos', 'active' => true, 'prices' => ['n0' => '44.90', 'n1' => '59.90'], 'recipes' => []],
            ],
        ]);
        $grande = $this->pizza->pizzaSizes()->orderBy('position')->get()->last();
        $this->grande = $grande->id;
        $flavors = $this->pizza->optionGroups()->where('kind', 'flavor')->firstOrFail()->options;
        $this->flavorIds = $flavors->pluck('id')->all();

        $group = new OptionGroup(['name' => 'Adicionais', 'kind' => 'extra', 'min_choices' => 0, 'max_choices' => 3, 'allows_quantity' => true, 'active' => true]);
        $group->company_id = $companyId;
        $group->save();
        $group->products()->attach($this->pizza->id, ['company_id' => $companyId, 'position' => 1]);
        $this->bacon = new Option(['name' => 'Bacon', 'price' => '5.00', 'active' => true, 'position' => 0]);
        $this->bacon->company_id = $companyId;
        $this->bacon->option_group_id = $group->id;
        $this->bacon->save();

        $sync = app(SyncOptionRecipe::class);
        $sync->handle(null, $grande, [['ingredient_id' => $this->flour->id, 'quantity' => '0.300']]);
        $sync->handle($flavors[0], $grande, [['ingredient_id' => $this->pepperoni->id, 'quantity' => '0.150']]);
        $sync->handle($flavors[1], $grande, [['ingredient_id' => $this->cheese->id, 'quantity' => '0.200']]);
        $sync->handle($this->bacon, null, [['ingredient_id' => $this->baconStock->id, 'quantity' => '0.030']]);
    }

    /** @return array<string, mixed> */
    private function line(string $quantity = '2'): array
    {
        return [
            'product_id' => $this->pizza->id, 'quantity' => $quantity, 'size_id' => $this->grande, 'flavor_ids' => $this->flavorIds,
            'options' => [['option_id' => $this->bacon->id, 'quantity' => 2]],
        ];
    }

    private function assertBalances(): void
    {
        $this->assertSame('99.400', $this->flour->fresh()->stock_quantity);
        $this->assertSame('99.850', $this->pepperoni->fresh()->stock_quantity);
        $this->assertSame('99.800', $this->cheese->fresh()->stock_quantity);
        $this->assertSame('99.880', $this->baconStock->fresh()->stock_quantity);
    }

    public function test_pos_sale_of_half_and_half_consumes_base_fractions_and_extras(): void
    {
        $this->openCash($this->admin);

        $this->post('/vendas', [
            'client_uuid' => (string) Str::uuid(),
            'items' => [[...$this->line(), 'expected_price' => '69.90']],
            'payments' => [['method' => 'pix', 'amount' => '139.80']],
        ])->assertSessionHasNoErrors();

        $this->assertBalances();
        $this->assertSame('Pizza · sabor Calabresa', StockMovement::where('product_id', $this->pepperoni->id)->sole()->notes);
    }

    public function test_pizza_does_not_consume_product_recipe(): void
    {
        $other = Product::factory()->create(['company_id' => $this->admin->company_id, 'stock_quantity' => '50', 'sale_price' => '1']);
        $this->pizza->recipeItems()->create(['company_id' => $this->admin->company_id, 'ingredient_id' => $other->id, 'quantity' => '1.000']);
        $this->openCash($this->admin);

        $this->post('/vendas', [
            'client_uuid' => (string) Str::uuid(),
            'items' => [[...$this->line('1'), 'expected_price' => '69.90']],
            'payments' => [['method' => 'pix', 'amount' => '69.90']],
        ])->assertSessionHasNoErrors();

        $this->assertSame('50.000', $other->fresh()->stock_quantity);
        $this->assertSame('99.700', $this->flour->fresh()->stock_quantity);
    }

    public function test_option_without_recipe_only_adds_price(): void
    {
        $plain = new Option(['name' => 'Cebola', 'price' => '3.00', 'active' => true, 'position' => 1]);
        $plain->company_id = $this->admin->company_id;
        $plain->option_group_id = $this->bacon->option_group_id;
        $plain->save();
        $this->openCash($this->admin);

        $this->post('/vendas', [
            'client_uuid' => (string) Str::uuid(),
            'items' => [['product_id' => $this->pizza->id, 'quantity' => '1', 'size_id' => $this->grande, 'flavor_ids' => [$this->flavorIds[0]], 'options' => [['option_id' => $plain->id]], 'expected_price' => '52.90']],
            'payments' => [['method' => 'pix', 'amount' => '52.90']],
        ])->assertSessionHasNoErrors();

        $this->assertSame('52.90', Sale::sole()->total);
        $this->assertSame('99.700', $this->flour->fresh()->stock_quantity);
        $this->assertSame('99.850', $this->pepperoni->fresh()->stock_quantity);
        $this->assertSame('100.000', $this->baconStock->fresh()->stock_quantity);
        $this->assertSame(2, StockMovement::count());
    }

    public function test_table_close_consumes_options_once(): void
    {
        $this->openCash($this->admin);
        $order = app(OpenTable::class)->handle(DiningTable::factory()->create(['company_id' => $this->admin->company_id]), 1, $this->admin);

        $this->post(route('orders.items', $order), ['client_uuid' => (string) Str::uuid(), 'items' => [$this->line()]])->assertSessionHasNoErrors();
        $this->assertSame(0, StockMovement::count());

        $this->deliverProduction($order);
        $this->post("/mesas/comandas/{$order->id}/fechar", [
            'client_uuid' => (string) Str::uuid(), 'expected_subtotal' => '139.80', 'charge_service' => false,
            'payments' => [['method' => 'pix', 'amount' => '139.80']],
        ])->assertSessionHasNoErrors();

        $this->assertBalances();
        $this->assertSame(4, StockMovement::count());
        $this->assertSame(4, StockMovement::distinct()->count('product_id'));
        $this->assertSame('Pizza · sabor Quatro queijos', StockMovement::where('product_id', $this->cheese->id)->sole()->notes);
        $this->assertSame(TableOrder::CLOSED, $order->fresh()->status);
        $this->assertCount(2, SaleItem::sole()->options['flavors']);
        $this->assertSame('Grande · ½ Calabresa ½ Quatro queijos · +2 Bacon', SaleItem::sole()->options_label);
    }

    public function test_delivery_settlement_consumes_options_and_sale_cost_includes_options(): void
    {
        $this->openCash($this->admin);
        $customer = Customer::factory()->create(['company_id' => $this->admin->company_id]);

        $this->post(route('deliveries.store'), [
            'client_uuid' => (string) Str::uuid(), 'customer_id' => $customer->id, 'type' => 'pickup',
            'items' => [$this->line()], 'payment_method' => 'cash',
        ])->assertSessionHasNoErrors();
        $this->assertSame(0, StockMovement::count());

        $delivery = DeliveryOrder::sole();
        $delivery->forceFill(['status' => DeliveryOrder::DELIVERED])->saveQuietly();
        ProductionWork::query()->update(['delivered_at' => now()]);

        $this->post("/entregas/{$delivery->id}/acertar", [
            'client_uuid' => (string) Str::uuid(), 'payments' => [['method' => 'cash', 'amount' => '139.80']],
        ])->assertSessionHasNoErrors();

        $this->assertBalances();
        $this->assertSame(4, StockMovement::count());
        // base 3,0000 + calabresa 1,5000 + queijo 4,0000 + bacon 1,5000 x 2
        $this->assertSame('11.5000', SaleItem::sole()->unit_cost);
    }
}
