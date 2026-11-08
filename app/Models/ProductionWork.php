<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProductionWork extends Model
{
    use Auditable, BelongsToCompany, HasFactory;

    protected function casts(): array
    {
        return ['options' => 'array', 'quantity' => 'decimal:3', 'version' => 'integer', 'legacy' => 'boolean', 'delivered_at' => 'datetime', 'cancelled_at' => 'datetime', 'reconciled_at' => 'datetime'];
    }

    public function tableItem(): BelongsTo
    {
        return $this->belongsTo(TableOrderItem::class, 'table_order_item_id');
    }

    public function deliveryItem(): BelongsTo
    {
        return $this->belongsTo(DeliveryOrderItem::class, 'delivery_order_item_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(ProductionEvent::class);
    }
}
