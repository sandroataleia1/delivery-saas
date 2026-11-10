<?php

namespace App\Actions;

use App\Models\DeliveryOrder;
use App\Support\ProductionLock;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Entrega que não deu certo: o pedido volta para a loja, que despacha de novo ou cancela. */
class ReturnDeliveryOrder
{
    public function handle(DeliveryOrder $order, string $reason): DeliveryOrder
    {
        return DB::transaction(function () use ($order, $reason) {
            /** @var DeliveryOrder $locked */
            $locked = ProductionLock::delivery($order);

            if ($locked->status !== DeliveryOrder::OUT) {
                throw ValidationException::withMessages(['order' => 'Só dá para marcar como não entregue um pedido em rota.']);
            }

            $locked->forceFill([
                'status' => DeliveryOrder::RETURNED,
                'returned_at' => now(),
                'return_reason' => $reason,
            ])->save();

            return $locked;
        });
    }
}
