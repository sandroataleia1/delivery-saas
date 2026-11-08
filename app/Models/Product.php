<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToCompany;
use App\Support\Decimal;
use Database\Factories\ProductFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

// stock_quantity e cost_price ficam fora de propósito: mudam apenas por MoveStock.
#[Fillable(['category_id', 'name', 'description', 'sku', 'barcode', 'unit', 'sale_price', 'min_stock', 'track_stock', 'sellable', 'sold_out'])]
class Product extends Model
{
    /** @use HasFactory<ProductFactory> */
    use Auditable, BelongsToCompany, HasFactory;

    protected function casts(): array
    {
        return [
            'sale_price' => 'decimal:2',
            'cost_price' => 'decimal:4',
            'stock_quantity' => 'decimal:3',
            'min_stock' => 'decimal:3',
            'track_stock' => 'boolean',
            'sellable' => 'boolean',
            'active' => 'boolean',
            'sold_out' => 'boolean',
        ];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function movements(): HasMany
    {
        return $this->hasMany(StockMovement::class);
    }

    public function recipeItems(): HasMany
    {
        return $this->hasMany(RecipeItem::class);
    }

    /** Custo atual dos insumos. Exige recipeItems.ingredient carregado. */
    public function recipeCost(): string
    {
        return $this->recipeItems->reduce(
            fn (string $sum, RecipeItem $item) => bcadd($sum, bcmul($item->quantity, $item->ingredient->cost_price, 4), 4),
            '0.0000',
        );
    }

    /** Custo que vale para o produto: da ficha técnica, quando houver. */
    public function effectiveCost(): string
    {
        return $this->recipeItems->isNotEmpty() ? $this->recipeCost() : $this->cost_price;
    }

    /** Quantas unidades o estoque atual permite fazer, ou null sem ficha técnica. */
    public function availablePortions(): ?int
    {
        return $this->recipeItems->isEmpty()
            ? null
            : $this->recipeItems->map(fn (RecipeItem $item) => (int) bcdiv($item->ingredient->stock_quantity, $item->quantity, 0))->min();
    }

    public function scopeLowStock(Builder $query): void
    {
        $query->where('track_stock', true)
            ->where('active', true)
            ->where(fn (Builder $query) => $query
                ->where('stock_quantity', '<', 0)
                ->orWhere(fn (Builder $query) => $query
                    ->where('min_stock', '>', 0)
                    ->whereColumn('stock_quantity', '<=', 'min_stock')));
    }

    public function isLowStock(): bool
    {
        return $this->track_stock
            && ($this->isNegative()
                || (bccomp($this->min_stock, '0', 3) > 0 && bccomp($this->stock_quantity, $this->min_stock, 3) <= 0));
    }

    public function isNegative(): bool
    {
        return bccomp($this->stock_quantity, '0', 3) < 0;
    }

    // O saldo é auditado linha a linha pelas movimentações.
    protected function auditExclude(): array
    {
        return ['stock_quantity'];
    }

    protected function auditDisplayValue(string $field, mixed $value): mixed
    {
        return match ($field) {
            'category_id' => $value ? Category::withoutGlobalScopes()->find($value)?->name ?? $value : $value,
            'sale_price', 'cost_price', 'min_stock' => Decimal::br($value),
            default => $value,
        };
    }
}
