<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToCompany;
use App\Support\Decimal;
use Database\Factories\StockEntryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['supplier_id', 'invoice_number', 'entry_date', 'notes'])]
class StockEntry extends Model
{
    /** @use HasFactory<StockEntryFactory> */
    use Auditable, BelongsToCompany, HasFactory;

    public const CONFIRMED = 'confirmed';

    public const CANCELLED = 'cancelled';

    protected function casts(): array
    {
        return [
            'entry_date' => 'date:Y-m-d',
            'total' => 'decimal:4',
            'cancelled_at' => 'datetime',
        ];
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(StockEntryItem::class);
    }

    public function isCancelled(): bool
    {
        return $this->status === self::CANCELLED;
    }

    public function auditLabel(): string
    {
        return '#'.$this->getKey();
    }

    protected function auditExclude(): array
    {
        return ['user_id', 'user_name'];
    }

    protected function auditDisplayValue(string $field, mixed $value): mixed
    {
        return match ($field) {
            'supplier_id' => $value ? Supplier::withoutGlobalScopes()->find($value)?->name ?? $value : $value,
            'total' => Decimal::br($value),
            'status' => __("stock.entry_status.$value"),
            default => $value,
        };
    }
}
