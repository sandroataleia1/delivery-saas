<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToCompany;
use App\Support\Decimal;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TableOrderItem extends Model
{
    use Auditable, BelongsToCompany;

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:3',
            'unit_price' => 'decimal:2',
            'total' => 'decimal:2',
            'cancelled_at' => 'datetime',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(TableOrder::class, 'table_order_id');
    }

    public function isCancelled(): bool
    {
        return $this->cancelled_at !== null;
    }

    public function auditLabel(): string
    {
        return Decimal::br($this->quantity)." {$this->unit} de {$this->product_name}";
    }

    protected function auditExclude(): array
    {
        return ['table_order_id', 'product_id', 'client_uuid', 'kitchen_batch', 'user_id', 'user_name', 'cancelled_at'];
    }

    protected function auditDisplayValue(string $field, mixed $value): mixed
    {
        return match ($field) {
            'quantity' => Decimal::br($value),
            'unit_price', 'total' => Decimal::currency($value),
            default => $value,
        };
    }
}
