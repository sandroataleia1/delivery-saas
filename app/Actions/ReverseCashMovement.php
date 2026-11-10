<?php

namespace App\Actions;

use App\Models\CashMovement;
use App\Models\CashSession;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Estorna uma sangria ou um suprimento lançado por engano.
 * O lançamento original fica como está: o estorno é outro lançamento, de efeito contrário, ligado a ele.
 */
class ReverseCashMovement
{
    public function __construct(private RecordCashMovement $recordMovement) {}

    public function handle(CashMovement $movement, string $reason): CashMovement
    {
        return DB::transaction(function () use ($movement, $reason) {
            /** @var CashMovement $locked */
            $locked = CashMovement::whereKey($movement->getKey())->lockForUpdate()->firstOrFail();
            $reversalType = CashMovement::REVERSALS[$locked->type] ?? null;

            if ($reversalType === null) {
                throw ValidationException::withMessages(['movement' => 'Só sangria e suprimento podem ser estornados aqui.']);
            }

            if (CashMovement::where('reverses_movement_id', $locked->id)->exists()) {
                throw ValidationException::withMessages(['movement' => 'Este lançamento já foi estornado.']);
            }

            return $this->recordMovement->handle(
                CashSession::findOrFail($locked->cash_session_id),
                $reversalType,
                $locked->amount,
                $reason,
                reverses: $locked,
            );
        });
    }
}
