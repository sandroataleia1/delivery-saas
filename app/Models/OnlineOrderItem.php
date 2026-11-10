<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OnlineOrderItem extends Model
{
    use BelongsToCompany;

    public $timestamps = false;

    protected function casts(): array
    {
        return ['unit_price' => 'decimal:2', 'total' => 'decimal:2', 'options' => 'array'];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(OnlineOrder::class, 'online_order_id');
    }
}
