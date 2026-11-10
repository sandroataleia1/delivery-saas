<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'slices', 'max_flavors', 'position', 'active'])]
class PizzaSize extends Model
{
    use Auditable, BelongsToCompany;

    protected function casts(): array
    {
        return [
            'slices' => 'integer',
            'max_flavors' => 'integer',
            'position' => 'integer',
            'active' => 'boolean',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** Ficha base do tamanho: linhas sem opção. */
    public function recipeItems(): HasMany
    {
        return $this->hasMany(OptionRecipeItem::class)->whereNull('option_id');
    }
}
