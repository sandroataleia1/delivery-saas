<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToCompany;
use App\Support\Decimal;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Opção de um grupo. Nos grupos de sabor o preço vem de option_prices, por tamanho. */
#[Fillable(['name', 'price', 'active', 'paused', 'position'])]
class Option extends Model
{
    use Auditable, BelongsToCompany;

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'active' => 'boolean',
            'paused' => 'boolean',
            'position' => 'integer',
        ];
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(OptionGroup::class, 'option_group_id');
    }

    public function prices(): HasMany
    {
        return $this->hasMany(OptionPrice::class);
    }

    public function recipeItems(): HasMany
    {
        return $this->hasMany(OptionRecipeItem::class);
    }

    protected function auditDisplayValue(string $field, mixed $value): mixed
    {
        return $field === 'price' ? Decimal::br($value) : $value;
    }
}
