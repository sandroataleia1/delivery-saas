<?php

namespace App\Actions;

use App\Models\Company;
use App\Models\Option;
use App\Models\OptionGroup;
use App\Models\OptionPrice;
use App\Models\PizzaSize;
use App\Models\Product;
use App\Support\Audit;
use App\Support\Decimal;
use Illuminate\Support\Facades\DB;

/** Configura tamanhos, sabores, preços por tamanho e fichas de uma pizza. */
class SavePizzaSetup
{
    public function __construct(private SyncOptionRecipe $syncRecipe) {}

    /**
     * Chaves de tamanho: o id de um tamanho existente, ou o índice de um novo (aceita o prefixo "n").
     *
     * @param  array{pizza_pricing: string, sizes: array<int, array<string, mixed>>, flavors: array<int, array<string, mixed>>, size_prices?: array<int|string, array<int|string, string>>}  $data
     */
    public function handle(Product $product, array $data): void
    {
        DB::transaction(function () use ($product, $data) {
            Company::whereKey($product->company_id)->lockForUpdate()->firstOrFail();
            $product = Product::whereKey($product->id)->lockForUpdate()->firstOrFail();
            $companyId = $product->company_id;

            $product->pizza_pricing = $data['pizza_pricing'];
            $product->save();

            $changes = [];
            $sizes = $this->saveSizes($product, array_values($data['sizes']), $changes);
            $group = $this->flavorGroup($product, $sizes);

            $keptIds = [];

            foreach (array_values($data['flavors']) as $position => $row) {
                $flavor = isset($row['id']) ? $group->options()->findOrFail($row['id']) : new Option;
                $flavor->fill(['name' => $row['name'], 'price' => 0, 'active' => $row['active'], 'position' => $position]);
                $flavor->company_id = $companyId;
                $flavor->option_group_id = $group->id;
                $flavor->save();
                $keptIds[] = $flavor->id;

                foreach ($row['prices'] ?? [] as $key => $price) {
                    $this->savePrice($flavor, $sizes[$this->resolveKey($key, $sizes)], $price, $changes);
                }

                foreach ($sizes as $size) {
                    $items = $this->recipeFor($row['recipes'] ?? [], $size, $sizes);
                    [$before, $after] = $this->syncRecipe->handle($flavor, $size, $items);
                    $this->noteChange($changes, "{$flavor->name} / {$size->name}", $before, $after);
                }
            }

            $group->options()->whereNotIn('id', $keptIds)->where('active', true)->get()->each(
                fn (Option $option) => $option->update(['active' => false])
            );

            foreach ($data['size_prices'] ?? [] as $optionId => $prices) {
                $option = Option::findOrFail($optionId);

                foreach ($prices as $key => $price) {
                    $this->savePrice($option, $sizes[$this->resolveKey($key, $sizes)], $price, $changes);
                }
            }

            if ($changes !== []) {
                Audit::record(
                    'updated',
                    $product,
                    old: ['pizza' => array_map(fn ($pair) => $pair[0], $changes)],
                    new: ['pizza' => array_map(fn ($pair) => $pair[1], $changes)],
                );
            }
        });
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @param  array<string, array{0: array<int, string>, 1: array<int, string>}>  $changes
     * @return array<string, PizzaSize> tamanhos por chave ("5" para existente, "n0" para novo)
     */
    private function saveSizes(Product $product, array $rows, array &$changes): array
    {
        $sizes = [];
        $keptIds = [];

        foreach ($rows as $position => $row) {
            $size = isset($row['id']) ? $product->pizzaSizes()->findOrFail($row['id']) : new PizzaSize;
            $size->fill([
                'name' => $row['name'],
                'slices' => $row['slices'] ?? null,
                'max_flavors' => $row['max_flavors'],
                'active' => $row['active'],
                'position' => $position,
            ]);
            $size->company_id = $product->company_id;
            $size->product_id = $product->id;
            $size->save();
            $keptIds[] = $size->id;
            $sizes[isset($row['id']) ? (string) $size->id : 'n'.$position] = $size;

            [$before, $after] = $this->syncRecipe->handle(null, $size, $row['recipe'] ?? []);
            $this->noteChange($changes, "Ficha base / {$size->name}", $before, $after);
        }

        $product->pizzaSizes()->whereNotIn('id', $keptIds)->where('active', true)->get()->each(
            fn (PizzaSize $size) => $size->update(['active' => false])
        );

        return $sizes;
    }

    /** @param array<string, PizzaSize> $sizes */
    private function flavorGroup(Product $product, array $sizes): OptionGroup
    {
        $group = $product->optionGroups()->where('kind', OptionGroup::FLAVOR)->first() ?? new OptionGroup(['kind' => OptionGroup::FLAVOR]);
        $maxFlavors = max(1, ...array_map(fn (PizzaSize $size) => $size->max_flavors, array_values($sizes)));

        $group->fill([
            'name' => mb_substr("Sabores de {$product->name}", 0, 80),
            'min_choices' => 1,
            'max_choices' => $maxFlavors,
            'allows_quantity' => false,
            'active' => true,
        ]);
        $group->company_id = $product->company_id;
        $group->save();

        $group->products()->syncWithoutDetaching([$product->id => ['company_id' => $product->company_id, 'position' => 0]]);

        return $group;
    }

    /** @param array<string, PizzaSize> $sizes */
    private function resolveKey(int|string $key, array $sizes): string
    {
        $key = (string) $key;

        if (isset($sizes[$key])) {
            return $key;
        }

        if (isset($sizes["n$key"])) {
            return "n$key";
        }

        throw new \InvalidArgumentException("Tamanho desconhecido: $key");
    }

    /**
     * @param  array<int|string, array<int, array{ingredient_id: int, quantity: string}>>  $recipes
     * @param  array<string, PizzaSize>  $sizes
     * @return array<int, array{ingredient_id: int, quantity: string}>
     */
    private function recipeFor(array $recipes, PizzaSize $size, array $sizes): array
    {
        foreach ($recipes as $key => $items) {
            if ($sizes[$this->resolveKey($key, $sizes)]->is($size)) {
                return $items;
            }
        }

        return [];
    }

    /** @param array<string, array{0: array<int, string>, 1: array<int, string>}> $changes */
    private function savePrice(Option $option, PizzaSize $size, string $price, array &$changes): void
    {
        $existing = OptionPrice::where('option_id', $option->id)->where('pizza_size_id', $size->id)->first();
        $price = Decimal::money($price);

        // A tabela não tem chave primária: atualiza pela chave composta.
        $updated = OptionPrice::where('option_id', $option->id)->where('pizza_size_id', $size->id)->update(['price' => $price]);

        if ($updated === 0) {
            OptionPrice::create([
                'company_id' => $option->company_id,
                'option_id' => $option->id,
                'pizza_size_id' => $size->id,
                'price' => $price,
            ]);
        }

        $this->noteChange(
            $changes,
            "Preço {$option->name} / {$size->name}",
            $existing ? [Decimal::currency($existing->price)] : [],
            [Decimal::currency($price)],
        );
    }

    /**
     * @param  array<string, array{0: array<int, string>, 1: array<int, string>}>  $changes
     * @param  array<int, string>  $before
     * @param  array<int, string>  $after
     */
    private function noteChange(array &$changes, string $label, array $before, array $after): void
    {
        if ($before !== $after) {
            $changes[$label] = [$before, $after];
        }
    }
}
