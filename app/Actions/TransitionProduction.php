<?php

namespace App\Actions;

use App\Models\DeliveryOrder;
use App\Models\DeliveryOrderItem;
use App\Models\ProductionWork;
use App\Support\ProductionGuard;
use App\Support\ProductionLock;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class TransitionProduction
{
    public function handle(ProductionWork $work, int $expectedVersion, string $target): ProductionWork
    {
        Gate::authorize($target === 'delivered' ? 'production.deliver' : 'production.operate');

        return DB::transaction(function () use ($work, $expectedVersion, $target) {
            $locked = ProductionLock::work($work);
            if ($locked->version !== $expectedVersion || $locked->cancelled_at || $locked->delivered_at
                || ($locked->legacy && ! $locked->reconciled_at)) {
                throw ValidationException::withMessages(['production' => 'Item alterado, encerrado ou aguardando reconciliação. Atualize o painel.']);
            }
            $next = ['waiting' => 'preparing', 'preparing' => 'ready', 'ready' => 'delivered'][$locked->status ?? ''] ?? null;
            if ($next !== $target || ($target === 'delivered' && $locked->delivery_order_item_id)) {
                throw ValidationException::withMessages(['production' => 'Etapa inválida. No delivery, conclua a entrega pelo quadro de entregas.']);
            }
            $from = $locked->status;
            $locked->forceFill(['status' => $target === 'delivered' ? 'ready' : $target,
                'delivered_at' => $target === 'delivered' ? now() : null, 'version' => $locked->version + 1])->save();
            ProductionGuard::event($locked, $from, $target);
            if ($locked->delivery_order_item_id) {
                $item = DeliveryOrderItem::findOrFail($locked->delivery_order_item_id);
                $order = DeliveryOrder::findOrFail($item->delivery_order_id);
                ProductionGuard::assertKnown($order);
                ProductionGuard::syncDeliveryStatus($order);
            }

            return $locked;
        });
    }
}
