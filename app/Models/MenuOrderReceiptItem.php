<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Database\Factories\MenuOrderReceiptItemFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;

class MenuOrderReceiptItem extends Model
{
    /** @use HasFactory<MenuOrderReceiptItemFactory> */
    use BelongsToCompany, HasFactory;

    protected function casts(): array
    {
        return ['unit_price' => 'decimal:2', 'total' => 'decimal:2'];
    }

    public function orderItem(): HasOne
    {
        return $this->hasOne(TableOrderItem::class, 'menu_order_receipt_item_id');
    }
}
