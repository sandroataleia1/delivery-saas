<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToCompany;
use Database\Factories\CategoryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'production_sector_id'])]
class Category extends Model
{
    /** @use HasFactory<CategoryFactory> */
    use Auditable, BelongsToCompany, HasFactory;

    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    /** Grupos de adicionais que valem para todos os produtos desta categoria. */
    public function optionGroups(): BelongsToMany
    {
        return $this->belongsToMany(OptionGroup::class, 'category_option_group')->orderBy('option_groups.name');
    }
}
