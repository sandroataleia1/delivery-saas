<?php

namespace App\Actions;

use App\Models\DeliveryOrder;
use App\Models\DeliveryOrderItem;
use App\Models\Product;
use App\Models\ProductionEvent;
use App\Models\ProductionWork;
use App\Models\TableOrder;
use App\Models\TableOrderItem;
use App\Support\ProductionDestination;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

/** Called only inside the originating transaction, with Company and origin locked. */
class DispatchProduction
{
    public function handle(TableOrderItem|DeliveryOrderItem $item): ProductionWork
    {
        $table = $item instanceof TableOrderItem;
        $foreign = $table ? 'table_order_item_id' : 'delivery_order_item_id';
        $existing = ProductionWork::withoutGlobalScopes()->where('company_id', $item->company_id)->where($foreign, $item->id)->first();
        if ($existing) {
            return $existing;
        }
        $product = Product::withoutGlobalScopes()->where('company_id', $item->company_id)->findOrFail($item->product_id);
        if (! $product->active || ! $product->sellable || $product->sold_out) {
            throw ValidationException::withMessages(['items' => 'Produto esgotado ou indisponível.']);
        }
        $sector = (new ProductionDestination)->resolve($product);
        $parent = $table ? TableOrder::withoutGlobalScopes()->where('company_id', $item->company_id)->findOrFail($item->table_order_id)
            : DeliveryOrder::withoutGlobalScopes()->where('company_id', $item->company_id)->findOrFail($item->delivery_order_id);
        if (($table && ! $parent->isOpen()) || (! $table && $parent->status !== DeliveryOrder::RECEIVED)) {
            throw ValidationException::withMessages(['production' => 'Origem indisponível para encaminhamento.']);
        }
        $work = new ProductionWork;
        $work->forceFill(['company_id' => $item->company_id, $foreign => $item->id, 'production_sector_id' => $sector->id,
            'sector_name' => $sector->name, ...$item->only('product_name', 'unit', 'quantity', 'notes'),
            'origin_label' => $table ? 'Mesa '.$parent->dining_table_id.' / comanda '.$parent->number : 'Entrega '.$parent->number,
            'status' => 'waiting', 'version' => 0, 'legacy' => false])->save();
        $event = new ProductionEvent;
        $event->forceFill(['company_id' => $item->company_id, 'production_work_id' => $work->id,
            'to_state' => 'waiting', 'version' => 0, 'actor_id' => Auth::id(),
            'actor_name' => Auth::user()?->name ?? 'Cliente (QR Code)'])->save();

        return $work;
    }
}
