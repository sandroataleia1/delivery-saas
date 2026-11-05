<?php

namespace App\Actions;

use App\Models\Product;
use App\Models\TableOrder;
use App\Models\TableOrderItem;
use App\Support\Decimal;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Lança itens na comanda com o preço atual, que fica valendo até o fechamento. */
class AddOrderItems
{
    /**
     * @param  array<int, array{product_id: int, quantity: string, notes?: ?string}>  $items
     */
    public function handle(TableOrder $order, array $items, string $clientUuid): void
    {
        DB::transaction(function () use ($order, $items, $clientUuid) {
            /** @var TableOrder $locked */
            $locked = TableOrder::whereKey($order->getKey())->lockForUpdate()->firstOrFail();

            if (! $locked->isOpen()) {
                throw ValidationException::withMessages(['order' => 'Esta comanda já foi fechada.']);
            }

            if ($locked->items()->where('client_uuid', $clientUuid)->exists()) {
                return;
            }

            $products = Product::findMany(collect($items)->pluck('product_id'))->keyBy('id');
            $user = Auth::user();

            foreach ($items as $item) {
                $product = $products[$item['product_id']];
                $orderItem = new TableOrderItem;

                $orderItem->forceFill([
                    'company_id' => $locked->company_id,
                    'table_order_id' => $locked->id,
                    'product_id' => $product->id,
                    'client_uuid' => $clientUuid,
                    'product_name' => $product->name,
                    'unit' => $product->unit,
                    'quantity' => $item['quantity'],
                    'unit_price' => $product->sale_price,
                    'total' => Decimal::money(bcmul((string) $item['quantity'], $product->sale_price, 5)),
                    'notes' => $item['notes'] ?? null,
                    'user_id' => $user->id,
                    'user_name' => $user->name,
                ])->save();
            }
        });
    }
}
