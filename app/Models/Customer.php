<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToCompany;
use App\Support\Decimal;
use Database\Factories\CustomerFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'phone', 'document', 'notes', 'allow_credit', 'credit_limit', 'credit_days'])]
class Customer extends Model
{
    /** @use HasFactory<CustomerFactory> */
    use Auditable, BelongsToCompany, HasFactory;

    protected function casts(): array
    {
        return [
            'allow_credit' => 'boolean',
            'credit_limit' => 'decimal:2',
            'active' => 'boolean',
        ];
    }

    public function addresses(): HasMany
    {
        return $this->hasMany(CustomerAddress::class);
    }

    public function bills(): HasMany
    {
        return $this->hasMany(Bill::class);
    }

    /** Quanto o cliente deve em fiado hoje. */
    public function openCredit(): string
    {
        return bcadd((string) $this->bills()->where('status', Bill::OPEN)->sum('amount'), '0', 2);
    }

    protected function auditDisplayValue(string $field, mixed $value): mixed
    {
        return $field === 'credit_limit' && $value !== null ? Decimal::currency($value) : $value;
    }
}
