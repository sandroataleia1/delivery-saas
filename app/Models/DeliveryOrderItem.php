<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable(['company_id', 'product_id', 'product_name', 'unit', 'quantity', 'unit_price', 'total', 'notes'])]
class DeliveryOrderItem extends Model
{
    use BelongsToCompany;

    public function production(): HasOne
    {
        return $this->hasOne(ProductionWork::class);
    }

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:3',
            'unit_price' => 'decimal:2',
            'total' => 'decimal:2',
        ];
    }
}
