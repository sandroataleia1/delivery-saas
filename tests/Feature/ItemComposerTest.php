<?php

namespace Tests\Feature;

use App\Actions\SavePizzaSetup;
use App\Actions\SyncOptionRecipe;
use App\Models\Company;
use App\Models\Option;
use App\Models\OptionGroup;
use App\Models\Product;
use App\Support\ItemComposer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ItemComposerTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Product $pizza;

    private OptionGroup $extras;

    private OptionGroup $edge;

    private Option $bacon;

    private Option $catupiry;

    private Option $calabresa;

    private Option $quatroQueijos;

    private int $broto;

    private int $grande;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::factory()->create();
        $this->pizza = Product::factory()->create(['company_id' => $this->company->id, 'name' => 'Pizza', 'sale_price' => '40', 'track_stock' => false]);

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

        $this->broto = $this->pizza->pizzaSizes()->orderBy('position')->firstOrFail()->id;
        $this->grande = $this->pizza->pizzaSizes()->orderBy('position')->get()->last()->id;
        $flavors = $this->pizza->optionGroups()->where('kind', 'flavor')->firstOrFail()->options;
        $this->calabresa = $flavors[0];
        $this->quatroQueijos = $flavors[1];

        $this->edge = $this->group('Borda', 0, 1, false);
        $this->catupiry = $this->option($this->edge, 'Catupiry', '8.00');
        $this->extras = $this->group('Adicionais', 0, 3, true);
        $this->bacon = $this->option($this->extras, 'Bacon', '5.00');

        $this->catupiry->prices()->create(['company_id' => $this->company->id, 'pizza_size_id' => $this->grande, 'price' => '10.00']);
    }

    private function group(string $name, int $min, int $max, bool $quantity, ?Product $product = null, ?int $companyId = null): OptionGroup
    {
        $group = new OptionGroup(['name' => $name, 'kind' => 'extra', 'min_choices' => $min, 'max_choices' => $max, 'allows_quantity' => $quantity, 'active' => true]);
        $group->company_id = $companyId ?? $this->company->id;
        $group->save();
        $position = ($product ?? $this->pizza)->optionGroups()->count();
        $group->products()->attach(($product ?? $this->pizza)->id, ['company_id' => $group->company_id, 'position' => $position]);

        return $group;
    }

    private function option(OptionGroup $group, string $name, string $price): Option
    {
        $option = new Option(['name' => $name, 'price' => $price, 'active' => true, 'position' => 0]);
        $option->company_id = $group->company_id;
        $option->option_group_id = $group->id;
        $option->save();

        return $option;
    }

    private function compose(array $item): array
    {
        return app(ItemComposer::class)->composeMany($this->company->id, [$item]);
    }

    private function assertRejected(array $item, string $key = 'items.0'): void
    {
        try {
            $this->compose($item);
            $this->fail('Era esperada uma ValidationException.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey($key, $exception->errors());
        }
    }

    private function pizzaItem(array $overrides = []): array
    {
        return [
            'product_id' => $this->pizza->id,
            'size_id' => $this->grande,
            'flavor_ids' => [$this->calabresa->id],
            ...$overrides,
        ];
    }

    public function test_highest_rule_prices_half_and_half_by_most_expensive_flavor(): void
    {
        $result = $this->compose($this->pizzaItem([
            'flavor_ids' => [$this->calabresa->id, $this->quatroQueijos->id],
            'options' => [
                ['option_id' => $this->catupiry->id],
                ['option_id' => $this->bacon->id, 'quantity' => 2],
            ],
        ]))[0];

        $this->assertSame('79.90', $result['unit_price']);
        $this->assertSame('Grande · ½ Calabresa ½ Quatro queijos · Borda: Catupiry · +2 Bacon', $result['options_label']);
        $this->assertTrue($result['product']->is($this->pizza));
    }

    public function test_average_rule_rounds_only_at_the_end(): void
    {
        $this->pizza->forceFill(['pizza_pricing' => 'average'])->save();
        $this->quatroQueijos->prices()->where('pizza_size_id', $this->grande)->update(['price' => '59.95']);
        $this->calabresa->prices()->where('pizza_size_id', $this->grande)->update(['price' => '49.90']);

        $result = $this->compose($this->pizzaItem(['flavor_ids' => [$this->calabresa->id, $this->quatroQueijos->id]]))[0];

        $this->assertSame('54.93', $result['unit_price']);
        $this->assertSame('average', $result['options']['pricing']);
    }

    public function test_snapshot_records_fractions_and_prices(): void
    {
        $result = $this->compose($this->pizzaItem([
            'flavor_ids' => [$this->calabresa->id, $this->quatroQueijos->id],
            'options' => [['option_id' => $this->bacon->id]],
        ]))[0];

        $this->assertSame(['id' => $this->grande, 'name' => 'Grande'], $result['options']['size']);
        $this->assertSame('highest', $result['options']['pricing']);
        $this->assertSame(
            [
                ['id' => $this->calabresa->id, 'name' => 'Calabresa', 'price' => '49.90', 'fraction' => '0.5000'],
                ['id' => $this->quatroQueijos->id, 'name' => 'Quatro queijos', 'price' => '59.90', 'fraction' => '0.5000'],
            ],
            $result['options']['flavors'],
        );
        $this->assertSame(
            [['id' => $this->bacon->id, 'group' => 'Adicionais', 'name' => 'Bacon', 'quantity' => 1, 'price' => '5.00']],
            $result['options']['extras'],
        );
        $this->assertSame('Grande · ½ Calabresa ½ Quatro queijos · +1 Bacon', $result['options_label']);
    }

    public function test_rejects_too_many_flavors_for_size(): void
    {
        $this->assertRejected($this->pizzaItem(['size_id' => $this->broto, 'flavor_ids' => [$this->calabresa->id, $this->quatroQueijos->id]]));
    }

    public function test_rejects_paused_flavor_and_paused_extra(): void
    {
        $this->calabresa->forceFill(['paused' => true])->save();
        $this->assertRejected($this->pizzaItem());

        $this->calabresa->forceFill(['paused' => false])->save();
        $this->bacon->forceFill(['paused' => true])->save();
        $this->assertRejected($this->pizzaItem(['options' => [['option_id' => $this->bacon->id]]]));
    }

    public function test_rejects_missing_required_group_and_quantity_without_allows_quantity(): void
    {
        $this->edge->forceFill(['min_choices' => 1])->save();
        $this->assertRejected($this->pizzaItem());

        $this->edge->forceFill(['min_choices' => 0])->save();
        $this->assertRejected($this->pizzaItem(['options' => [['option_id' => $this->catupiry->id, 'quantity' => 2]]]));
    }

    public function test_rejects_option_from_other_company_or_unlinked_group(): void
    {
        $otherCompany = Company::factory()->create();
        $foreignGroup = $this->group('Outra', 0, 1, false, $this->pizza, $otherCompany->id);
        $foreign = $this->option($foreignGroup, 'Alheia', '1.00');
        $this->assertRejected($this->pizzaItem(['options' => [['option_id' => $foreign->id]]]));

        $unlinked = new OptionGroup(['name' => 'Solta', 'kind' => 'extra', 'min_choices' => 0, 'max_choices' => 1, 'allows_quantity' => false, 'active' => true]);
        $unlinked->company_id = $this->company->id;
        $unlinked->save();
        $loose = $this->option($unlinked, 'Solta', '1.00');
        $this->assertRejected($this->pizzaItem(['options' => [['option_id' => $loose->id]]]));
    }

    public function test_plain_product_without_selection_keeps_sale_price_and_null_snapshot(): void
    {
        $plain = Product::factory()->create(['company_id' => $this->company->id, 'sale_price' => '12.50']);

        $result = app(ItemComposer::class)->composeMany($this->company->id, [['product_id' => $plain->id]])[0];

        $this->assertSame('12.50', $result['unit_price']);
        $this->assertNull($result['options']);
        $this->assertNull($result['options_label']);
    }

    public function test_plain_product_rejects_size_and_flavors(): void
    {
        $plain = Product::factory()->create(['company_id' => $this->company->id]);

        $this->assertRejected(['product_id' => $plain->id, 'size_id' => $this->grande]);
    }

    public function test_cost_includes_size_base_flavor_fraction_and_extras(): void
    {
        $flour = Product::factory()->create(['company_id' => $this->company->id, 'cost_price' => '10']);
        $pepperoni = Product::factory()->create(['company_id' => $this->company->id, 'cost_price' => '20']);
        $cheese = Product::factory()->create(['company_id' => $this->company->id, 'cost_price' => '40']);
        $sync = app(SyncOptionRecipe::class);
        $grande = $this->pizza->pizzaSizes()->findOrFail($this->grande);

        $sync->handle(null, $grande, [['ingredient_id' => $flour->id, 'quantity' => '0.300']]);
        $sync->handle($this->calabresa, $grande, [['ingredient_id' => $pepperoni->id, 'quantity' => '0.150']]);
        $sync->handle($this->bacon, null, [['ingredient_id' => $cheese->id, 'quantity' => '0.030']]);
        $sync->handle($this->catupiry, null, [['ingredient_id' => $cheese->id, 'quantity' => '0.050']]);
        $sync->handle($this->catupiry, $grande, [['ingredient_id' => $cheese->id, 'quantity' => '0.100']]);

        $result = $this->compose($this->pizzaItem([
            'flavor_ids' => [$this->calabresa->id, $this->quatroQueijos->id],
            'options' => [
                ['option_id' => $this->catupiry->id],
                ['option_id' => $this->bacon->id, 'quantity' => 2],
            ],
        ]))[0];

        // base 3,0000 + calabresa 3,0000 x 1/2 + borda do tamanho 4,0000 + bacon 1,2000 x 2
        $this->assertSame('10.9000', $result['cost']);
    }
}
