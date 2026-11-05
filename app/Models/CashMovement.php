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
        return in_array($this->type, [self::DEPOSIT, self::BILL_RECEIPT], true);
    }

    public function auditLabel(): string
    {
        return __("cash.movements.{$this->type}").' de R$ '.Decimal::currency($this->amount);
    }

    protected function auditExclude(): array
    {
        return ['cash_session_id', 'sale_id', 'user_id', 'user_name'];
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
