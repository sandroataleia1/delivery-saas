<?php

namespace App\Actions;

use App\Models\TableOrder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Libera a mesa sem venda. Nada foi baixado do estoque, pois isso só ocorre ao fechar a conta. */
class CancelTableOrder
{
    public function handle(TableOrder $order, string $reason): void
    {
        DB::transaction(function () use ($order, $reason) {
            /** @var TableOrder $locked */
            $locked = TableOrder::whereKey($order->getKey())->lockForUpdate()->firstOrFail();

            if (! $locked->isOpen()) {
                throw ValidationException::withMessages(['order' => 'Esta comanda já foi fechada.']);
            }

            $locked->forceFill([
                'status' => TableOrder::CANCELLED,
                'open_table_id' => null,
                'closed_at' => now(),
                'closed_by' => Auth::user()->name,
                'cancel_reason' => $reason,
            ])->save();
        });
    }
}
