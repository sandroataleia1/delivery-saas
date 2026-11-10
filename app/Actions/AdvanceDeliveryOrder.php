<?php

namespace App\Actions;

use App\Models\Courier;
use App\Models\DeliveryOrder;
use App\Models\User;
use App\Support\ProductionGuard;
use App\Support\ProductionLock;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AdvanceDeliveryOrder
{
    /**
     * @param  string  $expectedStatus  Etapa vista na tela: evita pular etapa em duplo clique.
     * @param  User|Courier|null  $courier  Entregador do cadastro ou, como antes, um usuário entregador.
     */
    public function handle(DeliveryOrder $order, string $expectedStatus, User|Courier|null $courier = null): DeliveryOrder
    {
        return DB::transaction(function () use ($order, $expectedStatus, $courier) {
            /** @var DeliveryOrder $locked */
            $locked = ProductionLock::delivery($order);

            if ($locked->status !== $expectedStatus) {
                throw ValidationException::withMessages(['order' => 'Este pedido já mudou de etapa. Atualize a tela.']);
            }

            ProductionGuard::assertKnown($locked);
            if (in_array($locked->status, [DeliveryOrder::RECEIVED, DeliveryOrder::PREPARING], true)) {
                throw ValidationException::withMessages(['production' => 'Atualize cada item no painel de produção.']);
            }
            $works = ProductionGuard::works($locked);
            if ($works->contains(fn ($w) => $w->status !== 'ready' || $w->cancelled_at)) {
                throw ValidationException::withMessages(['production' => 'Existem itens ainda não prontos.']);
            }
            $next = $locked->nextStatus();

            if ($next === null) {
                throw ValidationException::withMessages(['order' => 'Este pedido não tem próxima etapa.']);
            }

            if ($next === DeliveryOrder::OUT) {
                if (! $courier) {
                    throw ValidationException::withMessages(['courier_id' => 'Escolha o entregador.']);
                }

                $locked->courier_id = $courier instanceof Courier ? $courier->user_id : $courier->id;
                $locked->delivery_courier_id = $courier instanceof Courier ? $courier->id : null;
                $locked->courier_name = $courier->name;
                $locked->dispatched_at = now();
            }

            if ($next === DeliveryOrder::DELIVERED) {
                $locked->delivered_at = now();
                foreach ($works->whereNull('delivered_at') as $work) {
                    $work->forceFill(['delivered_at' => now(), 'version' => $work->version + 1])->save();
                    ProductionGuard::event($work, 'ready', 'delivered');
                }
            }

            $locked->status = $next;
            $locked->save();

            return $locked;
        });
    }
}
