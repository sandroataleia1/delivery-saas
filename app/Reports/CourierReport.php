<?php

namespace App\Reports;

use App\Models\Courier;
use App\Models\DeliveryOrder;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Entregas de cada entregador no período, pela data em que saíram para entrega.
 * O entregador vem como "c{id}" (cadastro) ou "u{id}" (usuário entregador sem cadastro).
 */
class CourierReport
{
    public function __construct(private Period $period, private ?string $courier = null) {}

    public function data(): array
    {
        $orders = $this->courier ? $this->orders() : collect();

        return [
            'courier' => $this->courier,
            'couriers' => self::options(),
            'summary' => $this->summary($orders),
            'orders' => $orders->map(fn (DeliveryOrder $order) => [
                'id' => $order->id,
                'number' => $order->number,
                'date' => $order->dispatched_at->format('d/m/Y H:i'),
                'customer' => $order->customer_name,
                'district' => $order->district,
                'status' => $order->status,
                'status_label' => __("deliveries.status.{$order->status}"),
                'payment' => config("sales.payment_methods.{$order->payment_method}"),
                'fee' => Money::of($order->delivery_fee),
                'total' => Money::of($order->total()),
                'route_minutes' => $order->delivered_at ? (int) $order->dispatched_at->diffInMinutes($order->delivered_at) : null,
                'return_reason' => $order->status === DeliveryOrder::RETURNED ? $order->return_reason : null,
            ])->values()->all(),
            'ranking' => $this->courier ? [] : $this->ranking(),
        ];
    }

    /** @return Collection<int, DeliveryOrder> */
    public function orders(): Collection
    {
        if ($this->courier === null) {
            return collect();
        }

        return $this->query()
            ->where($this->courier[0] === 'c' ? 'delivery_courier_id' : 'courier_id', (int) substr($this->courier, 1))
            ->orderBy('dispatched_at')
            ->orderBy('id')
            ->get();
    }

    /**
     * Todos os entregadores, inclusive os inativos, para escolher no filtro.
     *
     * @return list<array{key: string, name: string}>
     */
    public static function options(): array
    {
        $couriers = Courier::query()->orderBy('name')->get(['id', 'name', 'user_id']);
        $linked = $couriers->pluck('user_id')->filter()->all();

        return $couriers->map(fn (Courier $courier) => ['key' => 'c'.$courier->id, 'name' => $courier->name])
            ->concat(collect(User::activeCouriers())
                ->reject(fn (array $user) => in_array($user['id'], $linked, true))
                ->map(fn (array $user) => ['key' => 'u'.$user['id'], 'name' => $user['name']]))
            ->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)
            ->values()
            ->all();
    }

    private function query(): Builder
    {
        return DeliveryOrder::query()
            ->where('type', DeliveryOrder::DELIVERY)
            ->whereIn('status', [DeliveryOrder::OUT, DeliveryOrder::DELIVERED, DeliveryOrder::RETURNED])
            ->whereBetween('dispatched_at', $this->period->range());
    }

    /** @param  Collection<int, DeliveryOrder>  $orders */
    private function summary(Collection $orders): array
    {
        $delivered = $orders->where('status', DeliveryOrder::DELIVERED);
        $timed = $delivered->filter(fn (DeliveryOrder $order) => $order->delivered_at !== null);

        return [
            'delivered' => $delivered->count(),
            'returned' => $orders->where('status', DeliveryOrder::RETURNED)->count(),
            'out' => $orders->where('status', DeliveryOrder::OUT)->count(),
            'fees' => Money::of($delivered->sum('delivery_fee')),
            'total' => Money::of($delivered->sum(fn (DeliveryOrder $order) => (float) $order->total())),
            'route_minutes' => $timed->isEmpty() ? null : (int) round($timed->avg(fn (DeliveryOrder $order) => $order->dispatched_at->diffInMinutes($order->delivered_at))),
        ];
    }

    /** Sem entregador escolhido: um resumo de cada um no período. */
    private function ranking(): array
    {
        return $this->query()
            ->get(['id', 'status', 'courier_id', 'delivery_courier_id', 'courier_name', 'subtotal', 'delivery_fee', 'dispatched_at', 'delivered_at'])
            ->groupBy(fn (DeliveryOrder $order) => match (true) {
                $order->delivery_courier_id !== null => 'c'.$order->delivery_courier_id,
                $order->courier_id !== null => 'u'.$order->courier_id,
                default => 'n'.$order->courier_name,
            })
            ->map(fn (Collection $orders, string $key) => [
                'key' => str_starts_with($key, 'n') ? null : $key,
                'name' => $orders->last()->courier_name,
                ...$this->summary($orders),
            ])
            ->sortByDesc('delivered')
            ->values()
            ->all();
    }
}
