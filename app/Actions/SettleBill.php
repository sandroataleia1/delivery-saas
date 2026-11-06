<?php

namespace App\Actions;

use App\Models\Bill;
use App\Models\BillSettlement;
use App\Models\CashMovement;
use App\Models\CashSession;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Dá baixa na conta. Só o dinheiro do caixa mexe na gaveta; o resto segue por fora. */
class SettleBill
{
    public function __construct(private RecordCashMovement $recordCashMovement, private UpdateBillSettlementTotals $updateTotals) {}

    /**
     * @param  array{paid_at: string, paid_amount: string, payment_method: string, notes?: ?string, discount_amount?: ?string, discount_reason?: ?string}  $data
     */
    public function handle(Bill $bill, array $data): Bill
    {
        $data = Validator::make($data, [
            'paid_at' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'paid_amount' => ['required', 'numeric', 'decimal:0,2', 'gt:0', 'max:9999999'],
            'payment_method' => ['required', Rule::in(array_keys(config('finance.payment_methods')))],
            'notes' => ['nullable', 'string', 'max:255'],
            'discount_amount' => ['nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:9999999'],
            'discount_reason' => ['nullable', 'string', 'max:255'],
        ])->validate();

        return DB::transaction(function () use ($bill, $data) {
            /** @var Bill $locked */
            $locked = Bill::whereKey($bill->getKey())->lockForUpdate()->firstOrFail();

            if (! $locked->isOpen() || $locked->legacy_review_required) {
                throw ValidationException::withMessages(['bill' => 'Esta conta não está em aberto.']);
            }

            $amount = bcadd((string) $data['paid_amount'], '0', 2);
            $discount = bcadd((string) ($data['discount_amount'] ?? '0'), '0', 2);
            $balance = $locked->balance();
            if (bccomp($amount, $balance, 2) > 0) {
                throw ValidationException::withMessages(['paid_amount' => 'O valor é maior que o saldo da conta.']);
            }
            if (bccomp($discount, '0', 2) > 0) {
                Gate::authorize('finance.discount');
                if (trim($data['discount_reason'] ?? '') === '') {
                    throw ValidationException::withMessages(['discount_reason' => 'Informe o motivo do desconto.']);
                }
                if (bccomp(bcadd($amount, $discount, 2), $balance, 2) !== 0) {
                    throw ValidationException::withMessages(['discount_amount' => 'O desconto deve quitar exatamente o saldo restante.']);
                }
            }

            $movement = $data['payment_method'] === 'cash' ? $this->moveCash($locked, $data) : null;

            $settlement = new BillSettlement;
            $settlement->forceFill([
                'company_id' => $locked->company_id, 'bill_id' => $locked->id,
                'amount' => $amount, 'discount_amount' => $discount,
                'discount_reason' => bccomp($discount, '0', 2) > 0 ? $data['discount_reason'] : null,
                'paid_at' => $data['paid_at'], 'payment_method' => $data['payment_method'],
                'cash_movement_id' => $movement?->id, 'user_id' => Auth::id(), 'user_name' => Auth::user()->name,
                'notes' => $data['notes'] ?? null,
            ])->save();

            return $this->updateTotals->handle($locked);
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
