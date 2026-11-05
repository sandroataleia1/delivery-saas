<?php

namespace App\Actions;

use App\Models\Sale;
use App\Models\TableOrder;
use App\Models\TableOrderItem;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Fecha a conta como uma venda comum: mesmo caixa, estoque, ficha técnica e
 * auditoria do PDV. Preços são os de quando cada item foi pedido.
 */
class CloseTableOrder
{
    public function __construct(private RegisterSale $registerSale) {}

    /**
     * @param  array{client_uuid: string, expected_subtotal: string, discount?: ?string, charge_service: bool, payments: array}  $data
     * @return array{0: Sale, 1: array<int, string>}
     */
    public function handle(TableOrder $order, array $data): array
    {
        return DB::transaction(function () use ($order, $data) {
            /** @var TableOrder $locked */
            $locked = TableOrder::with('diningTable')->whereKey($order->getKey())->lockForUpdate()->firstOrFail();

            if (! $locked->isOpen()) {
                $sale = $locked->sale_id ? Sale::find($locked->sale_id) : null;

                if ($sale?->client_uuid === $data['client_uuid']) {
                    return [$sale, []];
                }

                throw ValidationException::withMessages(['order' => 'Esta comanda já foi fechada.']);
            }

            $items = $locked->activeItems()->orderBy('product_id')->orderBy('id')->get();

            if ($items->isEmpty()) {
                throw ValidationException::withMessages(['order' => 'Não há itens para fechar. Cancele a comanda para liberar a mesa.']);
            }

            if (bccomp($locked->subtotal(), (string) $data['expected_subtotal'], 2) !== 0) {
                throw ValidationException::withMessages(['order' => 'A comanda mudou enquanto você fechava. Confira os itens e tente de novo.']);
            }

            [$sale, $negative] = $this->registerSale->handle([
                'client_uuid' => $data['client_uuid'],
                'items' => $items
                    ->map(fn (TableOrderItem $item) => [
                        'product_id' => $item->product_id,
                        'quantity' => $item->quantity,
                        'fixed_price' => $item->unit_price,
                    ])
                    ->all(),
                'payments' => $data['payments'],
                'discount' => $data['discount'] ?? null,
                'customer_id' => $data['customer_id'] ?? null,
                'service_percent' => $data['charge_service'] ? Auth::user()->company->service_fee_percent : null,
                'origin' => 'table',
                'table_number' => $locked->diningTable->number,
            ]);

            $locked->forceFill([
                'status' => TableOrder::CLOSED,
                'open_table_id' => null,
                'sale_id' => $sale->id,
                'closed_at' => now(),
                'closed_by' => Auth::user()->name,
            ])->save();

            return [$sale, $negative];
        });
    }
}
