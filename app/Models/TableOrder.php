<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TableOrder extends Model
{
    use Auditable, BelongsToCompany;

    public const OPEN = 'open';

    public const CLOSED = 'closed';

    public const CANCELLED = 'cancelled';

    protected function casts(): array
    {
        return [
            'opened_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    public function diningTable(): BelongsTo
    {
        return $this->belongsTo(DiningTable::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(TableOrderItem::class)->orderBy('id');
    }

    public function activeItems(): HasMany
    {
        return $this->items()->whereNull('cancelled_at');
    }

    public function isOpen(): bool
    {
        return $this->status === self::OPEN;
    }

    public function subtotal(): string
    {
        return bcadd((string) $this->activeItems()->sum('total'), '0', 2);
    }

    public function auditLabel(): string
    {
        return "#{$this->number}";
    }

    protected function auditExclude(): array
    {
        return ['number', 'open_table_id', 'dining_table_id', 'kitchen_batch', 'sale_id', 'user_id', 'user_name', 'opened_at', 'closed_at'];
    }

    protected function auditDisplayValue(string $field, mixed $value): mixed
    {
        return $field === 'status' ? __("tables.status.$value") : $value;
    }
}
