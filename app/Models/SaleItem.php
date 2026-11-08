<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['company_id', 'product_id', 'product_name', 'unit', 'quantity', 'unit_price', 'unit_cost', 'total', 'options', 'options_label'])]
class SaleItem extends Model
{
    use BelongsToCompany;

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:3', 'options' => 'array',
            'unit_price' => 'decimal:2',
            'unit_cost' => 'decimal:4',
            'total' => 'decimal:2',
        ];
    }
}
