<?php

namespace App\Actions;

use App\Models\DeliveryOrder;
use App\Models\ProductionWork;
use App\Support\ProductionGuard;
use App\Support\ProductionLock;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Conclui de uma vez o preparo de todos os itens ativos de uma entrega, etapa por etapa,
 * pelas mesmas regras do painel de produção. A entrega passa a "Pronto" sozinha ao final.
 */
class MarkDeliveryReady
{
    public function __construct(private TransitionProduction $transition) {}

    public function handle(DeliveryOrder $order): DeliveryOrder
    {
        return DB::transaction(function () use ($order) {
            $locked = ProductionLock::delivery($order);

            if (! in_array($locked->status, [DeliveryOrder::RECEIVED, DeliveryOrder::PREPARING], true)) {
                throw ValidationException::withMessages(['online_order' => 'Este pedido não está mais em produção. Atualize a tela.']);
            }

            $works = ProductionGuard::assertKnown($locked)->reject(fn (ProductionWork $work) => $work->cancelled_at || $work->delivered_at);

            foreach ($works as $work) {
                foreach (['waiting' => ['preparing', 'ready'], 'preparing' => ['ready']][$work->status] ?? [] as $target) {
                    $work = $this->transition->handle($work, $work->version, $target);
                }
            }

            return $locked->refresh();
        });
    }
}
