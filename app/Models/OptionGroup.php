<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Grupo de opções de produtos: adicionais e bordas ('extra') ou sabores de pizza ('flavor'). */
#[Fillable(['name', 'kind', 'min_choices', 'max_choices', 'allows_quantity', 'active'])]
class OptionGroup extends Model
{
    use Auditable, BelongsToCompany;

    public const EXTRA = 'extra';

    public const FLAVOR = 'flavor';

    protected function casts(): array
    {
        return [
            'min_choices' => 'integer',
            'max_choices' => 'integer',
            'allows_quantity' => 'boolean',
            'active' => 'boolean',
        ];
    }

    public function options(): HasMany
    {
        return $this->hasMany(Option::class)->orderBy('position')->orderBy('id');
    }

    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'product_option_group')->withPivot('position');
    }

    /** Categorias cujos produtos recebem este grupo. */
    public function categories(): BelongsToMany
    {
        return $this->belongsToMany(Category::class, 'category_option_group');
    }
}
