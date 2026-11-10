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
use Illuminate\Database\Eloquent\Relations\HasMany;

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
            'discount_amount' => 'decimal:2',
            'legacy_review_required' => 'boolean',
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

    public function isLinked(): bool
    {
        return $this->sale_id !== null || $this->stock_entry_id !== null;
    }

    public function settlements(): HasMany
    {
        return $this->hasMany(BillSettlement::class);
    }

    /** Somente usado em consultas que já restringem a contas em aberto. */
    public static function balanceExpression(): string
    {
        return 'CASE WHEN bills.legacy_review_required = 1 THEN bills.amount ELSE bills.amount - COALESCE(bills.paid_amount, 0) - COALESCE(bills.discount_amount, 0) END';
    }

    public function balance(): string
    {
        if (! $this->isOpen()) {
            return '0.00';
        }

        return $this->legacy_review_required ? $this->amount
            : bcsub(bcsub($this->amount, $this->paid_amount ?? '0', 2), $this->discount_amount ?? '0', 2);
    }

    public function hasActiveSettlements(): bool
    {
        return bccomp($this->paid_amount ?? '0', '0', 2) > 0
            || bccomp($this->discount_amount ?? '0', '0', 2) > 0;
    }

    public function canEditDirectly(): bool
    {
        return $this->isOpen() && ! $this->isLinked() && ! $this->legacy_review_required
            && ($this->settlements_count !== null ? $this->settlements_count === 0 : ! $this->settlements()->exists());
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
            'amount', 'paid_amount', 'discount_amount' => $value === null ? null : Decimal::currency($value),
            'supplier_id' => $value ? Supplier::withoutGlobalScopes()->find($value)?->name ?? $value : $value,
            'status' => __("finance.status.$value"),
            'payment_method' => $value ? config("finance.payment_methods.$value") : null,
            default => $value,
        };
    }
}
