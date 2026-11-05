<?php

namespace App\Actions;

use App\Models\DeliveryOrder;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AdvanceDeliveryOrder
{
    /** @param  string  $expectedStatus  Etapa vista na tela: evita pular etapa em duplo clique. */
    public function handle(DeliveryOrder $order, string $expectedStatus, ?User $courier = null): DeliveryOrder
    {
        return DB::transaction(function () use ($order, $expectedStatus, $courier) {
            /** @var DeliveryOrder $locked */
            $locked = DeliveryOrder::whereKey($order->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->status !== $expectedStatus) {
                throw ValidationException::withMessages(['order' => 'Este pedido já mudou de etapa. Atualize a tela.']);
            }

            $next = $locked->nextStatus();

            if ($next === null) {
                throw ValidationException::withMessages(['order' => 'Este pedido não tem próxima etapa.']);
            }

            if ($next === DeliveryOrder::OUT) {
                if (! $courier) {
                    throw ValidationException::withMessages(['courier_id' => 'Escolha o entregador.']);
                }

                $locked->courier_id = $courier->id;
                $locked->courier_name = $courier->name;
                $locked->dispatched_at = now();
            }

            if ($next === DeliveryOrder::DELIVERED) {
                $locked->delivered_at = now();
            }

            $locked->status = $next;
            $locked->save();

            return $locked;
        });
    }
}
