<?php

namespace App\Actions;

use App\Models\Product;
use App\Models\TableOrder;
use App\Models\TableOrderItem;
use App\Support\Decimal;
use App\Support\ItemComposer;
use App\Support\TableOperationLock;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Lança itens na comanda com o preço atual, que fica valendo até o fechamento. */
class AddOrderItems
{
    public function __construct(private ItemComposer $composer) {}

    /**
     * @param  array<int, array{product_id: int, quantity: string, notes?: ?string, size_id?: ?int, flavor_ids?: array<int, int>, options?: array<int, array{option_id: int, quantity?: int}>}>  $items
     */
    public function handle(TableOrder $order, array $items, string $clientUuid, ?string $guestName = null): void
    {
        DB::transaction(function () use ($order, $items, $clientUuid, $guestName) {
            /** @var TableOrder $locked */
            $locked = TableOperationLock::order($order);

            if (! $locked->isOpen()) {
                throw ValidationException::withMessages(['order' => 'Esta comanda já foi fechada.']);
            }

            if ($locked->items()->where('client_uuid', $clientUuid)->exists()) {
                return;
            }

            $products = Product::where('company_id', $locked->company_id)->whereIn('id', collect($items)->pluck('product_id'))->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $composed = $this->composer->composeMany($locked->company_id, $items);
            $user = $guestName ? null : Auth::user();

            foreach ($items as $index => $item) {
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
                    'unit_price' => $composed[$index]['unit_price'],
                    'options' => $composed[$index]['options'],
                    'options_label' => $composed[$index]['options_label'],
                    'total' => Decimal::money(bcmul((string) $item['quantity'], $composed[$index]['unit_price'], 5)),
                    'notes' => $item['notes'] ?? null,
                    'user_id' => $user?->id,
                    'user_name' => $guestName ?? $user->name,
                ])->save();
                (new DispatchProduction)->handle($orderItem);
            }
        });
    }
}
