<?php

namespace App\Actions;

use App\Models\CashMovement;
use App\Models\CashSession;
use App\Models\Sale;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Sangria, suprimento, estorno ou conta paga em dinheiro. Saídas não podem passar do dinheiro no caixa. */
class RecordCashMovement
{
    public function handle(CashSession $session, string $type, string $amount, string $reason, ?Sale $sale = null): CashMovement
    {
        return DB::transaction(function () use ($session, $type, $amount, $reason, $sale) {
            /** @var CashSession $locked */
            $locked = CashSession::whereKey($session->getKey())->lockForUpdate()->firstOrFail();

            if (! $locked->isOpen()) {
                throw ValidationException::withMessages(['amount' => 'Este caixa já foi fechado.']);
            }

            $isOutflow = ! in_array($type, [CashMovement::DEPOSIT, CashMovement::BILL_RECEIPT], true);

            if ($isOutflow && bccomp($amount, $locked->calculateSummary()['expected_cash'], 2) > 0) {
                throw ValidationException::withMessages(['amount' => 'O valor é maior que o dinheiro disponível no caixa.']);
            }

            $user = Auth::user();
            $movement = new CashMovement;

            $movement->forceFill([
                'company_id' => $locked->company_id,
                'cash_session_id' => $locked->id,
                'sale_id' => $sale?->id,
                'type' => $type,
                'amount' => $amount,
                'reason' => $reason,
                'user_id' => $user->id,
                'user_name' => $user->name,
            ])->save();

            return $movement;
        });
    }
}
