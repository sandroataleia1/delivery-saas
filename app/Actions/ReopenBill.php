<?php

namespace App\Actions;

use App\Models\Bill;
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
    public function __construct(private RecordCashMovement $recordCashMovement) {}

    public function handle(Bill $bill, string $reason): void
    {
        DB::transaction(function () use ($bill, $reason) {
            /** @var Bill $locked */
            $locked = Bill::whereKey($bill->getKey())->lockForUpdate()->firstOrFail();

            if (! $locked->isPaid()) {
                throw ValidationException::withMessages(['reason' => 'Só é possível estornar uma conta quitada.']);
            }

            if ($locked->cash_movement_id) {
                $this->returnCash($locked, $reason);
            }

            Audit::record('bill_reopened', $locked, "Baixa estornada: {$locked->fullDescription()}", new: ['reason' => $reason]);

            $locked->forceFill([
                'status' => Bill::OPEN,
                'paid_at' => null,
                'paid_amount' => null,
                'payment_method' => null,
                'cash_movement_id' => null,
                'settled_by' => null,
            ])->save();
        });
    }

    private function returnCash(Bill $bill, string $reason): void
    {
        $session = CashSession::query()->openFor(Auth::user())->first();

        if (! $session) {
            throw ValidationException::withMessages(['reason' => 'Esta baixa usou dinheiro do caixa. Abra o seu caixa para estornar.']);
        }

        try {
            $this->recordCashMovement->handle(
                $session,
                $bill->isPayable() ? CashMovement::BILL_RECEIPT : CashMovement::BILL_PAYMENT,
                $bill->paid_amount,
                "Estorno de {$bill->fullDescription()}: $reason",
            );
        } catch (ValidationException $exception) {
            throw ValidationException::withMessages(['reason' => Arr::first(Arr::flatten($exception->errors()))]);
        }
    }
}
