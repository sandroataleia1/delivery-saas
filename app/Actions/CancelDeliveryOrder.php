<?php

namespace App\Actions;

use App\Models\DeliveryOrder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Cancelamento comum é permitido apenas antes da entrega e do acerto. */
class CancelDeliveryOrder
{
    public function handle(DeliveryOrder $order, string $reason): void
    {
        DB::transaction(function () use ($order, $reason) {
            /** @var DeliveryOrder $locked */
            $locked = DeliveryOrder::whereKey($order->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->isCancelled()) {
                throw ValidationException::withMessages(['reason' => 'Este pedido já foi cancelado.']);
            }

            if ($locked->isSettled()) {
                throw ValidationException::withMessages(['reason' => 'Este pedido já foi acertado. Cancele a venda em Vendas.']);
            }

            if ($locked->status === DeliveryOrder::DELIVERED) {
                throw ValidationException::withMessages(['reason' => 'Pedido entregue não permite cancelamento comum. É necessário um procedimento de devolução, perda ou ajuste financeiro.']);
            }

            $locked->forceFill([
                'status' => DeliveryOrder::CANCELLED,
                'cancelled_at' => now(),
                'cancelled_by' => Auth::user()->name,
                'cancel_reason' => $reason,
            ])->save();
        });
    }
}
