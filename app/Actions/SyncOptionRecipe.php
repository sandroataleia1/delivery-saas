<?php

namespace App\Actions;

use App\Models\Option;
use App\Models\OptionRecipeItem;
use App\Models\PizzaSize;
use App\Support\Decimal;
use Illuminate\Database\Eloquent\Builder;

/** Substitui uma ficha de opção ou de tamanho e devolve o antes e o depois, para a auditoria em bloco. */
class SyncOptionRecipe
{
    /**
     * @param  array<int, array{ingredient_id: int, quantity: string}>  $items
     * @return array{0: array<int, string>, 1: array<int, string>}
     */
    public function handle(?Option $option, ?PizzaSize $size, array $items): array
    {
        $companyId = ($option ?? $size)->company_id;
        $before = $this->describe($option, $size);

        $this->scope($option, $size)->delete();

        foreach ($items as $item) {
            $recipeItem = new OptionRecipeItem($item);
            $recipeItem->company_id = $companyId;
            $recipeItem->option_id = $option?->id;
            $recipeItem->pizza_size_id = $size?->id;
            $recipeItem->save();
        }

        return [$before, $this->describe($option, $size)];
    }

    /** @return Builder<OptionRecipeItem> */
    private function scope(?Option $option, ?PizzaSize $size): Builder
    {
        return OptionRecipeItem::query()
            ->where('option_id', $option?->id)
            ->where('pizza_size_id', $size?->id);
    }

    /** @return array<int, string> */
    private function describe(?Option $option, ?PizzaSize $size): array
    {
        return $this->scope($option, $size)
            ->with('ingredient:id,name,unit')
            ->get()
            ->sortBy('ingredient.name')
            ->map(fn (OptionRecipeItem $item) => Decimal::br($item->quantity)." {$item->ingredient->unit} de {$item->ingredient->name}")
            ->values()
            ->all();
    }
}
