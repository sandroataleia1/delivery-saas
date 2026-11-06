<?php

namespace App\Actions;

use App\Models\Bill;
use App\Models\CashMovement;
use App\Models\CashSession;
use App\Models\Sale;
use App\Models\StockMovement;
use App\Support\Audit;
use App\Support\Decimal;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Devolve ao estoque exatamente o que a venda baixou, lendo as movimentações:
 * funciona mesmo que a ficha técnica tenha mudado depois da venda.
 *
 * No dinheiro: com o caixa da venda aberto, a venda sai do esperado sozinha. Com
 * ele fechado, os totais ficam congelados e o valor sai como estorno do caixa
 * aberto de quem cancelou.
 */
class CancelSale
{
    public function __construct(
        private MoveStock $moveStock,
        private RecordCashMovement $recordCashMovement,
        private CancelBill $cancelBill,
    ) {}

    public function handle(Sale $sale, string $reason): void
    {
        DB::transaction(function () use ($sale, $reason) {
            /** @var Sale $locked */
            $locked = Sale::whereKey($sale->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->isCancelled()) {
                throw ValidationException::withMessages(['sale' => 'Esta venda já foi cancelada.']);
            }

            $this->cancelCredit($locked, $reason);
            $this->refundCash($locked);

            $movements = StockMovement::query()
                ->where('sale_id', $locked->id)
                ->with('product')
                ->orderBy('product_id')
                ->get();

            foreach ($movements as $movement) {
                $this->moveStock->handle(
                    $movement->product,
                    StockMovement::SALE_REVERSAL,
                    ltrim($movement->quantity, '-'),
                    notes: $reason,
                    sale: $locked,
                    allowNegative: true,
                );
            }

            $name = Auth::user()->name;

            $locked->forceFill([
                'status' => Sale::CANCELLED,
                'cancelled_at' => now(),
                'cancelled_by' => $name,
                'cancel_reason' => $reason,
            ])->save();

            Audit::record(
                'sale_cancelled',
                $locked,
                "Venda #{$locked->number} de R$ ".Decimal::currency($locked->total)." cancelada: $reason",
                old: ['status' => 'Concluída'],
                new: ['status' => 'Cancelada', 'cancel_reason' => $reason],
            );
        });
    }

    /** Fiado vira conta a receber: cancelada a venda, a conta deixa de existir. */
    private function cancelCredit(Sale $sale, string $reason): void
    {
        $bills = Bill::where('sale_id', $sale->id)->get();

        if ($bills->contains(fn (Bill $bill) => $bill->isPaid())) {
            throw ValidationException::withMessages(['sale' => 'O fiado desta venda já foi recebido. Estorne a baixa antes de cancelar.']);
        }

        $bills->filter(fn (Bill $bill) => $bill->isOpen())
            ->each(fn (Bill $bill) => $this->cancelBill->handle($bill, $reason));
    }

    private function refundCash(Sale $sale): void
    {
        $origin = CashSession::query()->whereKey($sale->cash_session_id)->lockForUpdate()->first();

        if ($origin?->isOpen()) {
            return;
        }

        $cash = (string) $sale->payments()->where('method', 'cash')->sum('amount');

        if (bccomp($cash, '0', 2) === 0) {
            return;
        }

        $session = CashSession::query()->openFor(Auth::user())->first();

        if (! $session) {
            throw ValidationException::withMessages([
                'sale' => 'O caixa desta venda já fechou. Abra o seu caixa: o dinheiro será devolvido dele.',
            ]);
        }

        try {
            $this->recordCashMovement->handle(
                $session,
                CashMovement::REFUND,
                bcadd($cash, '0', 2),
                "Cancelamento da venda #{$sale->number}",
                $sale,
            );
        } catch (ValidationException $exception) {
            throw ValidationException::withMessages(['sale' => Arr::first(Arr::flatten($exception->errors()))]);
        }
    }
}
