<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToCompany;
use App\Support\Decimal;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Livro do estoque. Registros não mudam: um erro se corrige com outro lançamento.
 */
class StockMovement extends Model
{
    use Auditable, BelongsToCompany;

    public const UPDATED_AT = null;

    public const INITIAL = 'initial';

    public const ENTRY = 'entry';

    public const ENTRY_REVERSAL = 'entry_reversal';

    public const ADJUSTMENT_IN = 'adjustment_in';

    public const ADJUSTMENT_OUT = 'adjustment_out';

    public const LOSS = 'loss';

    public const SALE = 'sale';

    public const SALE_INGREDIENT = 'sale_ingredient';

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:3',
            'balance_after' => 'decimal:3',
            'unit_cost' => 'decimal:4',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('A movimentação de estoque não pode ser alterada.'));
        static::deleting(fn () => throw new LogicException('A movimentação de estoque não pode ser excluída.'));
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function auditLabel(): string
    {
        $product = Product::withoutGlobalScopes()->find($this->product_id);

        $quantity = Decimal::br(ltrim($this->quantity, '-'));

        return __("stock.movements.{$this->type}")." de $quantity {$product?->unit} em {$product?->name}";
    }

    protected function auditExclude(): array
    {
        return ['user_id', 'user_name', 'product_id', 'stock_entry_id'];
    }

    protected function auditDisplayValue(string $field, mixed $value): mixed
    {
        return match ($field) {
            'type' => __("stock.movements.$value"),
            'quantity', 'balance_after', 'unit_cost' => Decimal::br($value),
            default => $value,
        };
    }
}
