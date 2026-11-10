<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Linha de ficha de opção ou tamanho. Sem tamanho: ficha geral da opção. Com ambos: ficha da
 * opção naquele tamanho. Sem opção: ficha base do tamanho. Auditada em bloco pelas actions.
 */
#[Fillable(['ingredient_id', 'quantity'])]
class OptionRecipeItem extends Model
{
    use BelongsToCompany;

    protected function casts(): array
    {
        return ['quantity' => 'decimal:3'];
    }

    public function ingredient(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'ingredient_id');
    }

    /** Linha no formato do RecipeEditor. Exige ingredient carregado. */
    public function toEditorRow(): array
    {
        return [
            'ingredient_id' => $this->ingredient_id,
            'name' => $this->ingredient->name,
            'unit' => $this->ingredient->unit,
            'cost_price' => $this->ingredient->cost_price,
            'stock_quantity' => $this->ingredient->stock_quantity,
            'quantity' => $this->quantity,
        ];
    }

    public function option(): BelongsTo
    {
        return $this->belongsTo(Option::class);
    }

    public function pizzaSize(): BelongsTo
    {
        return $this->belongsTo(PizzaSize::class);
    }
}
