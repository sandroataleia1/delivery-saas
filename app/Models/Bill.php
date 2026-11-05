<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToCompany;
use App\Support\Decimal;
use Database\Factories\BillFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Conta a pagar ou a receber. Valor pago e situação só mudam pelas ações de baixa. */
#[Fillable(['description', 'category', 'supplier_id', 'counterparty', 'amount', 'due_date', 'notes'])]
class Bill extends Model
{
    /** @use HasFactory<BillFactory> */
    use Auditable, BelongsToCompany, HasFactory;

    public const PAYABLE = 'payable';

    public const RECEIVABLE = 'receivable';

    public const OPEN = 'open';

    public const PAID = 'paid';

    public const CANCELLED = 'cancelled';

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'paid_amount' => 'decimal:2',
            'due_date' => 'date:Y-m-d',
            'paid_at' => 'date:Y-m-d',
            'cancelled_at' => 'datetime',
        ];
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function scopeOverdue(Builder $query): void
    {
        $query->where('status', self::OPEN)->whereDate('due_date', '<', today());
    }

    public function isOpen(): bool
    {
        return $this->status === self::OPEN;
    }

    public function isPaid(): bool
    {
        return $this->status === self::PAID;
    }

    public function isOverdue(): bool
    {
        return $this->isOpen() && $this->due_date->lt(today());
    }

    public function isPayable(): bool
    {
        return $this->type === self::PAYABLE;
    }

    /** Situação na linguagem da tela: em aberto vira "vencida" depois do vencimento. */
    public function statusKey(): string
    {
        return $this->isOverdue() ? 'overdue' : $this->status;
    }

    public function fullDescription(): string
    {
        return $this->installment_total > 1
            ? "{$this->description} ({$this->installment_number}/{$this->installment_total})"
            : $this->description;
    }

    public function auditLabel(): string
    {
        return $this->fullDescription();
    }

    protected function auditExclude(): array
    {
        return ['user_id', 'user_name', 'installment_group', 'stock_entry_id', 'cash_movement_id', 'type', 'installment_number', 'installment_total'];
    }

    protected function auditDisplayValue(string $field, mixed $value): mixed
    {
        return match ($field) {
            'amount', 'paid_amount' => $value === null ? null : Decimal::currency($value),
            'supplier_id' => $value ? Supplier::withoutGlobalScopes()->find($value)?->name ?? $value : $value,
            'status' => __("finance.status.$value"),
            'payment_method' => $value ? config("finance.payment_methods.$value") : null,
            default => $value,
        };
    }
}
