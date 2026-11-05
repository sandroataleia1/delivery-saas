<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToCompany;
use App\Support\Decimal;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CashSession extends Model
{
    use Auditable, BelongsToCompany;

    public const OPEN = 'open';

    public const CLOSED = 'closed';

    protected function casts(): array
    {
        return [
            'opening_amount' => 'decimal:2',
            'expected_cash' => 'decimal:2',
            'counted_cash' => 'decimal:2',
            'difference' => 'decimal:2',
            'summary' => 'array',
            'opened_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    public function movements(): HasMany
    {
        return $this->hasMany(CashMovement::class);
    }

    public function sales(): HasMany
    {
        return $this->hasMany(Sale::class);
    }

    public function scopeOpenFor(Builder $query, User $user): void
    {
        $query->where('open_user_id', $user->id);
    }

    public function isOpen(): bool
    {
        return $this->status === self::OPEN;
    }

    /** Totais calculados agora. Depois do fechamento, vale a cópia em $summary. */
    public function calculateSummary(): array
    {
        $byMethod = SalePayment::query()
            ->join('sales', 'sales.id', '=', 'sale_payments.sale_id')
            ->where('sales.cash_session_id', $this->id)
            ->where('sales.status', Sale::COMPLETED)
            ->selectRaw('sale_payments.method as method, SUM(sale_payments.amount) as total')
            ->groupBy('sale_payments.method')
            ->pluck('total', 'method');

        $movements = $this->movements()
            ->selectRaw('type, SUM(amount) as total')
            ->groupBy('type')
            ->pluck('total', 'type');

        $money = fn (mixed $value) => bcadd((string) ($value ?? '0'), '0', 2);

        $cash = $money($byMethod['cash'] ?? 0);
        $deposits = $money($movements[CashMovement::DEPOSIT] ?? 0);
        $withdrawals = $money($movements[CashMovement::WITHDRAWAL] ?? 0);
        $refunds = $money($movements[CashMovement::REFUND] ?? 0);
        $billPayments = $money($movements[CashMovement::BILL_PAYMENT] ?? 0);
        $billReceipts = $money($movements[CashMovement::BILL_RECEIPT] ?? 0);

        $sales = Sale::query()->where('cash_session_id', $this->id)->where('status', Sale::COMPLETED);

        $inflows = bcadd(bcadd(bcadd($this->opening_amount, $cash, 2), $deposits, 2), $billReceipts, 2);
        $outflows = bcadd(bcadd($withdrawals, $refunds, 2), $billPayments, 2);

        return [
            'sales_count' => (clone $sales)->count(),
            'sales_total' => $money((clone $sales)->sum('total')),
            'payments' => $byMethod->map(fn ($total) => $money($total))->all(),
            'deposits' => $deposits,
            'withdrawals' => $withdrawals,
            'refunds' => $refunds,
            'bill_payments' => $billPayments,
            'bill_receipts' => $billReceipts,
            'expected_cash' => bcsub($inflows, $outflows, 2),
        ];
    }

    public function auditLabel(): string
    {
        return "#{$this->number} de {$this->user_name}";
    }

    protected function auditExclude(): array
    {
        return ['number', 'open_user_id', 'user_id', 'user_name', 'summary', 'opened_at', 'closed_at'];
    }

    protected function auditDisplayValue(string $field, mixed $value): mixed
    {
        return match ($field) {
            'opening_amount', 'expected_cash', 'counted_cash', 'difference' => Decimal::currency($value),
            'status' => $value === self::OPEN ? 'Aberto' : 'Fechado',
            default => $value,
        };
    }
}
