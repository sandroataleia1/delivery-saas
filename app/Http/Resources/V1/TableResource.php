<?php

namespace App\Http\Resources\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Mesa do salão no app. Recebe uma linha de TableFloor::rows().
 * Status por prioridade: pedido do QR esperando > conta pedida > ocupada > livre.
 */
class TableResource extends JsonResource
{
    /**
     * @return array{id: int, number: int, area: ?string, seats: int, status: string, order: ?array{id: int, people: int, pending_count: int, waiter: string, total: string, minutes: int, closing: bool}}
     */
    public function toArray(Request $request): array
    {
        $order = $this->resource['order'];

        return [
            'id' => $this->resource['id'],
            'number' => $this->resource['number'],
            'area' => $this->resource['area'],
            'seats' => $this->resource['seats'],
            'status' => $this->status($order),
            'order' => $order ? [
                'id' => $order['id'],
                'people' => $order['people'],
                'pending_count' => $order['pending_count'],
                'waiter' => $order['waiter'],
                'total' => $order['total'],
                'minutes' => $order['minutes'],
                'closing' => $order['closing'],
            ] : null,
        ];
    }

    /**
     * @param  ?array{pending_count: int, closing: bool}  $order
     */
    private function status(?array $order): string
    {
        return match (true) {
            $order === null => 'free',
            $order['pending_count'] > 0 => 'pending',
            $order['closing'] => 'closing',
            default => 'open',
        };
    }
}
