<?php

namespace App\Http\Resources\V1;

use App\Models\MenuOrderReceipt;
use App\Models\MenuOrderReceiptItem;
use App\Models\TableOrder;
use App\Models\TableOrderItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Comanda da mesa no app, com a situação de cada item na produção. Itens cancelados vêm por último.
 *
 * @mixin TableOrder
 */
class TableOrderResource extends JsonResource
{
    /**
     * @return array{id: int, table: int, people: int, waiter: ?string, minutes: int, closing: bool, total: string, items: array<int, array{id: int, product_name: string, options_label: ?string, quantity: string, unit: string, total: string, notes: ?string, status: string, deliverable: bool}>, pending_receipts: array<int, array{id: int, total: string, created_at: string, items: array<int, array{product_name: string, quantity: int, options_label: ?string, notes: ?string}>}>}
     */
    public function toArray(Request $request): array
    {
        $this->resource->loadMissing(['diningTable', 'items.production', 'pendingReceipts.items']);

        return [
            'id' => $this->id,
            'table' => $this->diningTable->number,
            'people' => $this->people,
            'waiter' => $this->user_name,
            'minutes' => (int) $this->opened_at->diffInMinutes(now()),
            'closing' => $this->bill_requested_at !== null,
            'total' => $this->subtotal(),
            'items' => $this->items
                ->sortBy(fn (TableOrderItem $item) => [$item->isCancelled() ? 1 : 0, $item->id])
                ->map(fn (TableOrderItem $item) => [
                    'id' => $item->id,
                    'product_name' => $item->product_name,
                    'options_label' => $item->options_label,
                    'quantity' => $item->quantity,
                    'unit' => $item->unit,
                    'total' => $item->total,
                    'notes' => $item->notes,
                    'status' => $this->status($item),
                    'deliverable' => $this->deliverable($item),
                ])->values()->all(),
            'pending_receipts' => $this->pendingReceipts->sortBy('id')->map(fn (MenuOrderReceipt $receipt) => [
                'id' => $receipt->id,
                'total' => $receipt->total,
                'created_at' => $receipt->created_at->toIso8601String(),
                'items' => $receipt->items->map(fn (MenuOrderReceiptItem $line) => [
                    'product_name' => $line->product_name,
                    'quantity' => (int) $line->quantity,
                    'options_label' => $line->options_label,
                    'notes' => $line->notes,
                ])->values()->all(),
            ])->values()->all(),
        ];
    }

    /** Pronto na produção e ainda não levado à mesa. */
    private function deliverable(TableOrderItem $item): bool
    {
        return $this->status($item) === 'ready';
    }

    /** waiting | preparing | ready | delivered | cancelled | none (item sem produção). */
    private function status(TableOrderItem $item): string
    {
        $production = $item->production;

        return match (true) {
            $item->isCancelled() || $production?->cancelled_at !== null => 'cancelled',
            $production === null => 'none',
            $production->delivered_at !== null => 'delivered',
            default => $production->status,
        };
    }
}
