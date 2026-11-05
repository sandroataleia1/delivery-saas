<?php

namespace App\Actions;

use App\Models\DeliveryOrder;
use App\Models\DeliveryOrderItem;
use App\Models\Sale;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Acerto no caixa: vira uma venda comum, com estoque, ficha técnica, caixa e auditoria. */
class SettleDeliveryOrder
{
    public function __construct(private RegisterSale $registerSale) {}

    /**
     * @param  array{client_uuid: string, discount?: ?string, payments: array}  $data
     * @return array{0: Sale, 1: array<int, string>}
     */
    public function handle(DeliveryOrder $order, array $data): array
    {
        return DB::transaction(function () use ($order, $data) {
            /** @var DeliveryOrder $locked */
            $locked = DeliveryOrder::whereKey($order->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->isSettled()) {
                $sale = Sale::find($locked->sale_id);

                if ($sale?->client_uuid === $data['client_uuid']) {
                    return [$sale, []];
                }

                throw ValidationException::withMessages(['order' => 'Este pedido já foi acertado.']);
            }

            if ($locked->status !== DeliveryOrder::DELIVERED) {
                throw ValidationException::withMessages(['order' => 'O pedido só é acertado depois de entregue.']);
            }

            [$sale, $negative] = $this->registerSale->handle([
                'client_uuid' => $data['client_uuid'],
                'items' => $locked->items()->orderBy('product_id')->orderBy('id')->get()
                    ->map(fn (DeliveryOrderItem $item) => [
                        'product_id' => $item->product_id,
                        'quantity' => $item->quantity,
                        'fixed_price' => $item->unit_price,
                    ])
                    ->all(),
                'payments' => $data['payments'],
                'discount' => $data['discount'] ?? null,
                'delivery_fee' => $locked->delivery_fee,
                'customer_id' => $locked->customer_id,
                'origin' => 'delivery',
            ]);

            $locked->forceFill(['sale_id' => $sale->id])->saveQuietly();

            return [$sale, $negative];
        });
    }
}
