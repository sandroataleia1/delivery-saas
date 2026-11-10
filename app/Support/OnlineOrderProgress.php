<?php

namespace App\Support;

use App\Models\DeliveryOrder;
use App\Models\OnlineOrder;
use App\Models\OnlineOrderItem;

/** Linha do tempo pública do pedido online: só o que o cliente pode ver, sem telefone nem endereço. */
class OnlineOrderProgress
{
    public const EXPIRED_REASON = 'A loja não confirmou a tempo.';

    /**
     * @return array{number: int, type: string, state: string, steps: list<array{key: string, label: string, at: ?string, done: bool, current: bool}>, reason: ?string, total: string, items: list<array{product_name: string, options_label: ?string, quantity: int, total: string}>}
     */
    public static function for(OnlineOrder $order): array
    {
        $delivery = DeliveryOrder::withoutGlobalScopes()->where('online_order_id', $order->id)->first();
        $pickup = $order->type === DeliveryOrder::PICKUP;
        $status = $delivery?->status;

        $state = match (true) {
            $order->status === OnlineOrder::REJECTED => 'rejected',
            $order->status === OnlineOrder::EXPIRED => 'expired',
            $status === DeliveryOrder::CANCELLED => 'cancelled',
            $delivery === null => 'pending',
            $status === DeliveryOrder::DELIVERED => 'delivered',
            in_array($status, [DeliveryOrder::OUT, DeliveryOrder::RETURNED], true) => 'ready',
            $status === DeliveryOrder::READY => $pickup ? 'ready' : 'preparing',
            $status === DeliveryOrder::PREPARING => 'preparing',
            default => 'accepted',
        };

        $terminal = in_array($state, ['rejected', 'expired', 'cancelled'], true);
        $accepted = $delivery !== null;
        $reached = [
            'received' => true,
            'accepted' => $accepted,
            'preparing' => ! $terminal && in_array($status, [DeliveryOrder::PREPARING, DeliveryOrder::READY, DeliveryOrder::OUT, DeliveryOrder::RETURNED, DeliveryOrder::DELIVERED], true),
            'ready' => ! $terminal && in_array($status, $pickup ? [DeliveryOrder::READY, DeliveryOrder::DELIVERED] : [DeliveryOrder::OUT, DeliveryOrder::RETURNED, DeliveryOrder::DELIVERED], true),
            'delivered' => ! $terminal && $status === DeliveryOrder::DELIVERED,
        ];
        $labels = [
            'received' => 'Recebido',
            'accepted' => 'Aceito pela loja',
            'preparing' => 'Em preparo',
            'ready' => $pickup ? 'Pronto para retirar' : 'Saiu para entrega',
            'delivered' => $pickup ? 'Retirado' : 'Entregue',
        ];
        $times = [
            'received' => $order->created_at,
            'accepted' => $accepted ? $order->decided_at : null,
            'preparing' => null,
            'ready' => $delivery?->dispatched_at,
            'delivered' => $delivery?->delivered_at,
        ];
        $lastDone = collect($reached)->filter()->keys()->last();

        $steps = [];
        foreach ($labels as $key => $label) {
            $steps[] = [
                'key' => $key,
                'label' => $label,
                'at' => $reached[$key] ? $times[$key]?->copy()->timezone('America/Sao_Paulo')->toIso8601String() : null,
                'done' => $reached[$key],
                'current' => ! $terminal && $key === $lastDone,
            ];
        }

        return [
            'number' => (int) $order->number,
            'type' => $order->type,
            'state' => $state,
            'steps' => $steps,
            'reason' => match ($state) {
                'rejected' => $order->decision_reason,
                'expired' => self::EXPIRED_REASON,
                'cancelled' => $delivery?->cancel_reason,
                default => null,
            },
            'total' => (string) $order->total,
            'items' => OnlineOrderItem::withoutGlobalScopes()->where('online_order_id', $order->id)->orderBy('id')->get()
                ->map(fn (OnlineOrderItem $item): array => [
                    'product_name' => $item->product_name,
                    'options_label' => $item->options_label,
                    'quantity' => (int) $item->quantity,
                    'total' => (string) $item->total,
                ])->all(),
        ];
    }
}
