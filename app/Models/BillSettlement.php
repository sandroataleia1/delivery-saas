<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToCompany;
use Database\Factories\BillSettlementFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BillSettlement extends Model
{
    /** @use HasFactory<BillSettlementFactory> */
    use Auditable, BelongsToCompany, HasFactory;

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'discount_amount' => 'decimal:2', 'paid_at' => 'date:Y-m-d',
            'reversed_at' => 'datetime', 'legacy_imported' => 'boolean'];
    }

    public function bill(): BelongsTo
    {
        return $this->belongsTo(Bill::class);
    }

    public function auditLabel(): string
    {
        return "Baixa #{$this->id} da conta #{$this->bill_id}";
    }
}
