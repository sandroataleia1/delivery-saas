<?php

namespace App\Reports;

use App\Models\DeliveryOrder;

class DeliveriesReport
{
    public function __construct(private Period $period) {}

    public function data(): array
    {
        $orders = DeliveryOrder::query()
            ->where('status', DeliveryOrder::DELIVERED)
            ->whereBetween('created_at', $this->period->range())
            ->get(['id', 'type', 'courier_name', 'district', 'subtotal', 'delivery_fee', 'created_at', 'dispatched_at', 'delivered_at']);

        $deliveries = $orders->where('type', DeliveryOrder::DELIVERY);
        $cancelled = DeliveryOrder::query()
            ->where('status', DeliveryOrder::CANCELLED)
            ->whereBetween('created_at', $this->period->range())
            ->count();

        return [
            'summary' => [
                'orders' => $orders->count(),
                'deliveries' => $deliveries->count(),
                'pickups' => $orders->count() - $deliveries->count(),
                'cancelled' => $cancelled,
                'fees' => Money::of($deliveries->sum('delivery_fee')),
                'average_minutes' => $this->averageMinutes($deliveries, 'created_at'),
            ],
            'couriers' => $deliveries->groupBy('courier_name')
                ->map(fn ($group, string $name) => [
                    'label' => $name,
                    'count' => $group->count(),
                    'fees' => Money::of($group->sum('delivery_fee')),
                    'route_minutes' => $this->averageMinutes($group, 'dispatched_at'),
                ])
                ->sortByDesc('count')
                ->values()
                ->all(),
            'districts' => $deliveries->groupBy('district')
                ->map(fn ($group, string $name) => [
                    'label' => $name,
                    'count' => $group->count(),
                    'total' => Money::of($group->sum(fn (DeliveryOrder $order) => (float) $order->total())),
                ])
                ->sortByDesc('count')
                ->values()
                ->take(20)
                ->all(),
        ];
    }

    private function averageMinutes($orders, string $start): ?int
    {
        $timed = $orders->filter(fn (DeliveryOrder $order) => $order->{$start} && $order->delivered_at);

        return $timed->isEmpty()
            ? null
            : (int) round($timed->avg(fn (DeliveryOrder $order) => $order->{$start}->diffInMinutes($order->delivered_at)));
    }
}
