<?php

namespace App\Actions;

use App\Models\MenuOrderReceipt;
use App\Models\Product;
use App\Models\TableOrder;
use App\Models\TableOrderItem;
use App\Support\TableOperationLock;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class ConfirmMenuOrder
{
    public function handle(MenuOrderReceipt $receipt): MenuOrderReceipt
    {
        Gate::authorize('tables.confirm');

        return DB::transaction(function () use ($receipt) {
            $order = TableOperationLock::order(TableOrder::findOrFail($receipt->table_order_id));
            $locked = MenuOrderReceipt::whereKey($receipt->id)->where('company_id', $order->company_id)->lockForUpdate()->firstOrFail();
            if ($locked->status === 'confirmed') {
                return $locked;
            }
            if (! $order->isOpen() || $locked->status !== 'pending') {
                throw ValidationException::withMessages(['receipt' => 'Este pedido não está disponível para confirmação.']);
            }
            $lines = $locked->items()->get();
            $products = Product::whereIn('id', $lines->pluck('product_id'))->where('active', true)->where('sellable', true)->where('sold_out', false)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            foreach ($lines as $line) {
                if (! $products->has($line->product_id)) {
                    throw ValidationException::withMessages(['receipt' => 'Um produto ficou indisponível. Resolva ou rejeite o pedido.']);
                }
                $orderItem = new TableOrderItem;
                $orderItem->forceFill(['company_id' => $order->company_id, 'table_order_id' => $order->id,
                    'menu_order_receipt_item_id' => $line->id, 'client_uuid' => $locked->client_uuid,
                    ...$line->only('product_id', 'product_name', 'unit', 'quantity', 'unit_price', 'total', 'notes'),
                    'user_id' => null, 'user_name' => PlaceMenuOrder::GUEST])->save();
                (new DispatchProduction)->handle($orderItem);
            }
            $locked->forceFill(['status' => 'confirmed', 'decided_at' => now(), 'decided_by_id' => Auth::id(), 'decided_by' => Auth::user()->name])->save();

            return $locked;
        });
    }
}
