<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToCompany;
use App\Support\Decimal;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/** Registros não mudam: um erro se corrige com outro lançamento. */
class CashMovement extends Model
{
    use Auditable, BelongsToCompany;

    public const UPDATED_AT = null;

    public const WITHDRAWAL = 'withdrawal';

    public const DEPOSIT = 'deposit';

    public const REFUND = 'refund';

    public const BILL_PAYMENT = 'bill_payment';

    public const BILL_RECEIPT = 'bill_receipt';

    /** Estorno de sangria: o dinheiro volta para a gaveta. */
    public const WITHDRAWAL_REVERSAL = 'withdrawal_reversal';

    /** Estorno de suprimento: o dinheiro sai da gaveta. */
    public const DEPOSIT_REVERSAL = 'deposit_reversal';

    /** Lançamentos que o operador pode estornar e o tipo do estorno de cada um. */
    public const REVERSALS = [self::WITHDRAWAL => self::WITHDRAWAL_REVERSAL, self::DEPOSIT => self::DEPOSIT_REVERSAL];

    public const INFLOWS = [self::DEPOSIT, self::BILL_RECEIPT, self::WITHDRAWAL_REVERSAL];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('O lançamento de caixa não pode ser alterado.'));
        static::deleting(fn () => throw new LogicException('O lançamento de caixa não pode ser excluído.'));
    }

    public function isInflow(): bool
    {
        return in_array($this->type, self::INFLOWS, true);
    }

    /**
     * Sangrias e suprimentos já descontados dos estornos, a partir dos totais por tipo.
     *
     * @param  iterable<string, mixed>  $totalsByType
     * @return array{withdrawals: string, deposits: string}
     */
    public static function netTotals(iterable $totalsByType): array
    {
        $totals = collect($totalsByType);
        $money = fn (string $type) => bcadd((string) ($totals[$type] ?? '0'), '0', 2);

        return [
            'withdrawals' => bcsub($money(self::WITHDRAWAL), $money(self::WITHDRAWAL_REVERSAL), 2),
            'deposits' => bcsub($money(self::DEPOSIT), $money(self::DEPOSIT_REVERSAL), 2),
        ];
    }

    public function auditLabel(): string
    {
        return __("cash.movements.{$this->type}").' de R$ '.Decimal::currency($this->amount);
    }

    protected function auditExclude(): array
    {
        return ['cash_session_id', 'sale_id', 'reverses_movement_id', 'user_id', 'user_name'];
    }

    protected function auditDisplayValue(string $field, mixed $value): mixed
    {
        return match ($field) {
            'type' => __("cash.movements.$value"),
            'amount' => Decimal::currency($value),
            default => $value,
        };
    }
}
