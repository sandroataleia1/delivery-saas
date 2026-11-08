<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToCompany;
use App\Support\Decimal;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DeliveryOrder extends Model
{
    use Auditable, BelongsToCompany;

    public const DELIVERY = 'delivery';

    public const PICKUP = 'pickup';

    public const RECEIVED = 'received';

    public const PREPARING = 'preparing';

    public const READY = 'ready';

    public const OUT = 'out_for_delivery';

    public const DELIVERED = 'delivered';

    public const CANCELLED = 'cancelled';

    protected function casts(): array
    {
        return [
            'subtotal' => 'decimal:2',
            'delivery_fee' => 'decimal:2',
            'change_for' => 'decimal:2',
            'dispatched_at' => 'datetime',
            'delivered_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function items(): HasMany
    {
        return $this->hasMany(DeliveryOrderItem::class)->orderBy('id');
    }

    public function onlineOrder(): BelongsTo
    {
        return $this->belongsTo(OnlineOrder::class);
    }

    public function total(): string
    {
        return bcadd($this->subtotal, $this->delivery_fee, 2);
    }

    public function isDelivery(): bool
    {
        return $this->type === self::DELIVERY;
    }

    public function isCancelled(): bool
    {
        return $this->status === self::CANCELLED;
    }

    public function isSettled(): bool
    {
        return $this->sale_id !== null;
    }

    /** Próxima etapa. Retirada no balcão não passa por "em rota". */
    public function nextStatus(): ?string
    {
        return match ($this->status) {
            self::RECEIVED => self::PREPARING,
            self::PREPARING => self::READY,
            self::READY => $this->isDelivery() ? self::OUT : self::DELIVERED,
            self::OUT => self::DELIVERED,
            default => null,
        };
    }

    public function address(): ?string
    {
        if (! $this->isDelivery()) {
            return null;
        }

        return trim("{$this->street}, {$this->number_address}".($this->complement ? " - {$this->complement}" : '')." - {$this->district}");
    }

    public function auditLabel(): string
    {
        return "#{$this->number} de {$this->customer_name}";
    }

    protected function auditExclude(): array
    {
        return ['number', 'customer_id', 'courier_id', 'sale_id', 'user_id', 'user_name', 'dispatched_at', 'delivered_at', 'cancelled_at', 'customer_phone'];
    }

    protected function auditDisplayValue(string $field, mixed $value): mixed
    {
        return match ($field) {
            'status' => __("deliveries.status.$value"),
            'type' => __("deliveries.types.$value"),
            'subtotal', 'delivery_fee' => Decimal::currency($value),
            'change_for' => $value === null ? null : Decimal::currency($value),
            'payment_method' => config("sales.payment_methods.$value"),
            default => $value,
        };
    }
}
