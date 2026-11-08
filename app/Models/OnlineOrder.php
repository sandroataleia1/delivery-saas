<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class OnlineOrder extends Model
{
    use Auditable, BelongsToCompany;

    public const AWAITING_PAYMENT = 'awaiting_payment';

    public const PENDING = 'pending';

    public const ACCEPTED = 'accepted';

    public const REJECTED = 'rejected';

    public const EXPIRED = 'expired';

    protected function casts(): array
    {
        return [
            'tracking_token' => 'encrypted',
            'subtotal' => 'decimal:2',
            'delivery_fee' => 'decimal:2',
            'total' => 'decimal:2',
            'change_for' => 'decimal:2',
            'expires_at' => 'datetime',
            'decided_at' => 'datetime',
        ];
    }

    public function items(): HasMany
    {
        return $this->hasMany(OnlineOrderItem::class)->orderBy('id');
    }

    public function deliveryOrder(): HasOne
    {
        return $this->hasOne(DeliveryOrder::class, 'online_order_id');
    }

    public function whatsappMessages(): HasMany
    {
        return $this->hasMany(WhatsAppMessage::class);
    }

    protected function auditExclude(): array
    {
        return ['tracking_token', 'tracking_token_hash', 'content_hash'];
    }

    public function auditLabel(): string
    {
        return 'Pedido online #'.$this->number;
    }
}
