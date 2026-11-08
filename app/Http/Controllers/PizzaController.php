<?php

namespace App\Http\Controllers;

use App\Actions\SavePizzaSetup;
use App\Http\Requests\PizzaSetupRequest;
use App\Models\Option;
use App\Models\OptionGroup;
use App\Models\OptionRecipeItem;
use App\Models\PizzaSize;
use App\Models\Product;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class PizzaController extends Controller
{
    public function edit(Product $product): Response
    {
        $product->load([
            'pizzaSizes.recipeItems.ingredient:id,name,unit,cost_price,stock_quantity',
            'optionGroups.options.prices',
            'optionGroups.options.recipeItems.ingredient:id,name,unit,cost_price,stock_quantity',
        ]);
        $editorRows = fn ($items) => $items->sortBy('ingredient.name')->values()->map(fn (OptionRecipeItem $item) => $item->toEditorRow());
        $flavorGroup = $product->optionGroups->firstWhere('kind', OptionGroup::FLAVOR);

        return Inertia::render('products/Pizza', [
            'product' => $product->only('id', 'name', 'pizza_pricing'),
            'sizes' => $product->pizzaSizes->map(fn (PizzaSize $size) => [
                'id' => $size->id,
                'name' => $size->name,
                'slices' => $size->slices,
                'max_flavors' => $size->max_flavors,
                'active' => $size->active,
                'recipe' => $editorRows($size->recipeItems),
            ])->values(),
            'flavors' => ($flavorGroup?->options ?? collect())->map(fn (Option $option) => [
                'id' => $option->id,
                'name' => $option->name,
                'active' => $option->active,
                'prices' => $option->prices->mapWithKeys(fn ($price) => [$price->pizza_size_id => $price->price]),
                'recipes' => $option->recipeItems->whereNotNull('pizza_size_id')->groupBy('pizza_size_id')->map($editorRows),
            ])->values(),
            'extras' => $product->optionGroups->where('kind', OptionGroup::EXTRA)->map(fn (OptionGroup $group) => [
                'id' => $group->id,
                'name' => $group->name,
                'options' => $group->options->map(fn (Option $option) => [
                    'id' => $option->id,
                    'name' => $option->name,
                    'prices' => $option->prices->mapWithKeys(fn ($price) => [$price->pizza_size_id => $price->price]),
                ])->values(),
            ])->values(),
        ]);
    }

    public function update(PizzaSetupRequest $request, Product $product, SavePizzaSetup $action): RedirectResponse
    {
        $action->handle($product, $request->validated());

        return redirect()->route('products.pizza.edit', $product)->with('success', 'Pizza atualizada.');
    }
}
