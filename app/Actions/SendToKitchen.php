<?php

namespace App\Actions;

use App\Models\TableOrder;
use Illuminate\Support\Facades\DB;

/** Agrupa o que ainda não foi para a cozinha em um novo pedido. Retorna o número dele. */
class SendToKitchen
{
    public function handle(TableOrder $order): ?int
    {
        return DB::transaction(function () use ($order) {
            /** @var TableOrder $locked */
            $locked = TableOrder::whereKey($order->getKey())->lockForUpdate()->firstOrFail();

            $pending = $locked->activeItems()->whereNull('kitchen_batch');

            if (! $pending->exists()) {
                return null;
            }

            $batch = $locked->kitchen_batch + 1;

            // Sem eventos de modelo: é controle interno, não uma alteração do pedido.
            $pending->update(['kitchen_batch' => $batch]);
            $locked->forceFill(['kitchen_batch' => $batch])->saveQuietly();

            return $batch;
        });
    }
}
