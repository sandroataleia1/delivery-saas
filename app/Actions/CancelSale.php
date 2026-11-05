<?php

namespace App\Actions;

use App\Models\Sale;
use App\Models\StockMovement;
use App\Support\Audit;
use App\Support\Decimal;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Devolve ao estoque exatamente o que a venda baixou, lendo as movimentações:
 * funciona mesmo que a ficha técnica tenha mudado depois da venda.
 */
class CancelSale
{
    public function __construct(private MoveStock $moveStock) {}

    public function handle(Sale $sale, string $reason): void
    {
        DB::transaction(function () use ($sale, $reason) {
            /** @var Sale $locked */
            $locked = Sale::whereKey($sale->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->isCancelled()) {
                throw ValidationException::withMessages(['sale' => 'Esta venda já foi cancelada.']);
            }

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
}
