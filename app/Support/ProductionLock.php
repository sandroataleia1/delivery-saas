<?php

namespace App\Support;

use App\Models\Company;
use App\Models\DeliveryOrder;
use App\Models\DeliveryOrderItem;
use App\Models\ProductionWork;
use App\Models\TableOrder;
use App\Models\TableOrderItem;
use Illuminate\Validation\ValidationException;

class ProductionLock
{
    public static function delivery(DeliveryOrder $order): DeliveryOrder
    {
        Company::whereKey($order->company_id)->lockForUpdate()->firstOrFail();

        return DeliveryOrder::where('company_id', $order->company_id)->whereKey($order->id)->lockForUpdate()->firstOrFail();
    }

    public static function work(ProductionWork $work): ProductionWork
    {
        Company::whereKey($work->company_id)->lockForUpdate()->firstOrFail();
        if ($work->table_order_item_id) {
            $item = TableOrderItem::where('company_id', $work->company_id)->findOrFail($work->table_order_item_id);
            $origin = TableOperationLock::order(TableOrder::where('company_id', $work->company_id)->findOrFail($item->table_order_id));
            if (! $origin->isOpen()) {
                throw ValidationException::withMessages(['production' => 'Comanda encerrada.']);
            }
        } else {
            $item = DeliveryOrderItem::where('company_id', $work->company_id)->findOrFail($work->delivery_order_item_id);
            $origin = self::delivery(DeliveryOrder::where('company_id', $work->company_id)->findOrFail($item->delivery_order_id));
            if ($origin->isSettled() || $origin->isCancelled()) {
                throw ValidationException::withMessages(['production' => 'Pedido encerrado.']);
            }
        }

        return ProductionWork::where('company_id', $work->company_id)->whereKey($work->id)->lockForUpdate()->firstOrFail();
    }
}
