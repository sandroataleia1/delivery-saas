<?php

namespace App\Actions;

use App\Models\Bill;
use App\Models\BillSettlement;
use App\Models\CashMovement;
use App\Models\CashSession;
use App\Support\Audit;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Estorna a baixa e reabre a conta. O lançamento original não muda: se saiu ou
 * entrou dinheiro no caixa, a volta é um novo lançamento no caixa aberto.
 */
class ReopenBill
{
    public function __construct(private RecordCashMovement $recordCashMovement, private UpdateBillSettlementTotals $updateTotals) {}

    public function handle(Bill $bill, string $reason, ?int $settlementId = null): void
    {
        DB::transaction(function () use ($bill, $reason, $settlementId) {
            /** @var Bill $locked */
            $locked = Bill::whereKey($bill->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->status === Bill::CANCELLED || $locked->legacy_review_required) {
                throw ValidationException::withMessages(['reason' => 'Conta cancelada ou com legado pendente de reconciliação.']);
            }

            $active = $locked->settlements()->whereNull('reversed_at')->get();
            if ($settlementId === null && $active->count() !== 1) {
                throw ValidationException::withMessages(['settlement_id' => 'Selecione a baixa que deseja estornar.']);
            }
            $settlement = $settlementId === null ? $active->sole() : $active->firstWhere('id', $settlementId);
            if (! $settlement) {
                throw ValidationException::withMessages(['settlement_id' => 'Baixa não disponível para estorno nesta conta.']);
            }
            $movement = $settlement->cash_movement_id ? $this->returnCash($locked, $settlement, $reason) : null;

            $settlement->forceFill([
                'reversed_at' => now(), 'reversed_by_id' => Auth::id(), 'reversed_by' => Auth::user()->name,
                'reversal_reason' => $reason, 'reversal_cash_movement_id' => $movement?->id,
            ])->save();
            Audit::record('bill_reopened', $locked, "Baixa #{$settlement->id} estornada: {$locked->fullDescription()}", new: [
                'reason' => $reason, 'settlement_id' => $settlement->id,
                'amount' => $settlement->amount, 'discount_amount' => $settlement->discount_amount,
            ]);
            $this->updateTotals->handle($locked);
        });
    }

    private function returnCash(Bill $bill, BillSettlement $settlement, string $reason): CashMovement
    {
        $session = CashSession::query()->openFor(Auth::user())->first();

        if (! $session) {
            throw ValidationException::withMessages(['reason' => 'Esta baixa usou dinheiro do caixa. Abra o seu caixa para estornar.']);
        }

        try {
            return $this->recordCashMovement->handle(
                $session,
                $bill->isPayable() ? CashMovement::BILL_RECEIPT : CashMovement::BILL_PAYMENT,
                $settlement->amount,
                "Estorno de {$bill->fullDescription()}: $reason",
            );
        } catch (ValidationException $exception) {
            throw ValidationException::withMessages(['reason' => Arr::first(Arr::flatten($exception->errors()))]);
        }
    }
}
