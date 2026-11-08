<?php

namespace App\Support;

use App\Models\Option;
use App\Models\OptionGroup;
use App\Models\PizzaSize;
use App\Models\Product;
use Illuminate\Support\Collection;

/**
 * Descreve para a tela o que dá para montar em cada produto (tamanhos, sabores e grupos de opções).
 * Só informa; preço, validação e rótulo continuam sendo calculados no servidor por ItemComposer.
 */
class ProductComposition
{
    /**
     * @param  Collection<int, Product>  $products
     * @return array<int, array{pizza: array<string, mixed>|null, groups: array<int, array<string, mixed>>}>
     */
    public static function forProducts(Collection $products): array
    {
        if ($products->isEmpty()) {
            return [];
        }

        $loaded = Product::query()
            ->whereIn('id', $products->pluck('id')->all())
            ->with(['pizzaSizes', 'optionGroups.options.prices'])
            ->get();

        $result = [];

        foreach ($loaded as $product) {
            $groups = $product->optionGroups->filter(fn (OptionGroup $group) => $group->active);
            $extras = $groups->filter(fn (OptionGroup $group) => $group->kind === OptionGroup::EXTRA);
            $flavorGroup = $groups->first(fn (OptionGroup $group) => $group->kind === OptionGroup::FLAVOR);

            if (! $product->isPizza() && $extras->isEmpty()) {
                continue;
            }

            $result[$product->id] = [
                'pizza' => $product->isPizza() ? [
                    'pricing' => $product->pizza_pricing,
                    'sizes' => $product->pizzaSizes->where('active', true)->map(fn (PizzaSize $size) => [
                        'id' => $size->id, 'name' => $size->name, 'slices' => $size->slices, 'max_flavors' => $size->max_flavors,
                    ])->values()->all(),
                    'flavors' => $flavorGroup === null ? [] : $flavorGroup->options->where('active', true)
                        ->map(fn (Option $option) => self::option($option))->values()->all(),
                ] : null,
                'groups' => $extras->map(fn (OptionGroup $group) => [
                    'id' => $group->id,
                    'name' => $group->name,
                    'min' => $group->min_choices,
                    'max' => $group->max_choices,
                    'allows_quantity' => $group->allows_quantity,
                    'options' => $group->options->where('active', true)->map(fn (Option $option) => self::option($option))->values()->all(),
                ])->values()->all(),
            ];
        }

        return $result;
    }

    /** @return array{id: int, name: string, price: string, paused: bool, prices: array<int, string>} */
    private static function option(Option $option): array
    {
        return [
            'id' => $option->id,
            'name' => $option->name,
            'price' => $option->price,
            'paused' => $option->paused,
            'prices' => $option->prices->mapWithKeys(fn ($price) => [$price->pizza_size_id => $price->price])->all(),
        ];
    }
}
