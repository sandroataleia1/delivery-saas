<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Database\Factories\MenuOrderReceiptFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MenuOrderReceipt extends Model
{
    /** @use HasFactory<MenuOrderReceiptFactory> */
    use \App\Models\Concerns\BelongsToCompany, Auditable, HasFactory;

    public const PENDING = 'pending';

    public const CONFIRMED = 'confirmed';

    public const REJECTED = 'rejected';

    protected function casts(): array
    {
        return ['total' => 'decimal:2', 'policy_snapshot' => 'array', 'decided_at' => 'datetime'];
    }

    public function items(): HasMany
    {
        return $this->hasMany(MenuOrderReceiptItem::class)->orderBy('id');
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(TableOrder::class, 'table_order_id');
    }

    protected function auditExclude(): array
    {
        return ['tracking_key_hash', 'content_hash', 'service_context'];
    }

    public function auditLabel(): string
    {
        return 'Recebimento #'.$this->id;
    }
}
