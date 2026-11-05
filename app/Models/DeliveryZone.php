<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToCompany;
use App\Support\Decimal;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['name', 'fee'])]
class DeliveryZone extends Model
{
    use Auditable, BelongsToCompany;

    protected function casts(): array
    {
        return ['fee' => 'decimal:2'];
    }

    protected function auditDisplayValue(string $field, mixed $value): mixed
    {
        return $field === 'fee' ? Decimal::currency($value) : $value;
    }
}
