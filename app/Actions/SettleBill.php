<?php

namespace App\Actions;

use App\Models\Bill;
use App\Models\CashMovement;
use App\Models\CashSession;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Dá baixa na conta. Só o dinheiro do caixa mexe na gaveta; o resto segue por fora. */
class SettleBill
{
    public function __construct(private RecordCashMovement $recordCashMovement) {}

    /**
     * @param  array{paid_at: string, paid_amount: string, payment_method: string, notes?: ?string}  $data
     */
    public function handle(Bill $bill, array $data): Bill
    {
        return DB::transaction(function () use ($bill, $data) {
            /** @var Bill $locked */
            $locked = Bill::whereKey($bill->getKey())->lockForUpdate()->firstOrFail();

            if (! $locked->isOpen()) {
                throw ValidationException::withMessages(['bill' => 'Esta conta não está em aberto.']);
            }

            $movement = $data['payment_method'] === 'cash' ? $this->moveCash($locked, $data) : null;

            $locked->forceFill([
                'status' => Bill::PAID,
                'paid_at' => $data['paid_at'],
                'paid_amount' => $data['paid_amount'],
                'payment_method' => $data['payment_method'],
                'cash_movement_id' => $movement?->id,
                'settled_by' => Auth::user()->name,
                'notes' => $data['notes'] ?? $locked->notes,
            ])->save();

            return $locked;
        });
    }

    private function moveCash(Bill $bill, array $data): CashMovement
    {
        if ($data['paid_at'] !== today()->format('Y-m-d')) {
            throw ValidationException::withMessages(['paid_at' => 'Dinheiro do caixa só pode ter a data de hoje.']);
        }

        $session = CashSession::query()->openFor(Auth::user())->first();

        if (! $session) {
            throw ValidationException::withMessages(['payment_method' => 'Abra o seu caixa para usar dinheiro do caixa.']);
        }

        try {
            return $this->recordCashMovement->handle(
                $session,
                $bill->isPayable() ? CashMovement::BILL_PAYMENT : CashMovement::BILL_RECEIPT,
                bcadd((string) $data['paid_amount'], '0', 2),
                $bill->fullDescription(),
            );
        } catch (ValidationException $exception) {
            throw ValidationException::withMessages(['paid_amount' => Arr::first(Arr::flatten($exception->errors()))]);
        }
    }
}
