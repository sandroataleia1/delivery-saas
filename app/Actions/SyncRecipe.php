<?php

namespace App\Actions;

use App\Models\Product;
use App\Models\RecipeItem;
use App\Support\Audit;
use App\Support\Decimal;
use Illuminate\Support\Facades\DB;

/** Substitui a ficha técnica e registra um único antes e depois na auditoria. */
class SyncRecipe
{
    /**
     * @param  array<int, array{ingredient_id: int, quantity: string}>  $items
     */
    public function handle(Product $product, array $items): void
    {
        DB::transaction(function () use ($product, $items) {
            $before = $this->describe($product);

            $product->recipeItems()->delete();

            foreach ($items as $item) {
                $recipeItem = new RecipeItem($item);
                $recipeItem->company_id = $product->company_id;
                $recipeItem->product_id = $product->id;
                $recipeItem->save();
            }

            $after = $this->describe($product);

            if ($before !== $after) {
                Audit::record('updated', $product, old: ['recipe' => $before], new: ['recipe' => $after]);
            }
        });
    }

    /** @return array<int, string> */
    private function describe(Product $product): array
    {
        return RecipeItem::query()
            ->where('product_id', $product->id)
            ->with('ingredient:id,name,unit')
            ->get()
            ->sortBy('ingredient.name')
            ->map(fn (RecipeItem $item) => Decimal::br($item->quantity)." {$item->ingredient->unit} de {$item->ingredient->name}")
            ->values()
            ->all();
    }
}
