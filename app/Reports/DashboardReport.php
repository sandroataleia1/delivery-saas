<?php

namespace App\Reports;

use App\Models\Bill;
use App\Models\DeliveryOrder;
use App\Models\OnlineOrder;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\SalePayment;
use App\Models\TableOrder;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Números da tela inicial. Cada bloco é calculado à parte para que a tela peça só o que o
 * usuário pode ver e para que a operação ao vivo seja atualizada sem refazer o resto.
 */
class DashboardReport
{
    private const CHANNELS = ['counter' => 'Balcão', 'table' => 'Mesas', 'delivery' => 'Entregas', 'online' => 'Loja online'];

    public function __construct(private CarbonImmutable $now) {}

    /**
     * Hoje até agora contra o mesmo dia da semana passada até a mesma hora; mês até hoje contra o mês anterior até o mesmo dia.
     *
     * @return array<string, array{total: string, count: int, average: string}>
     */
    public function sales(): array
    {
        $today = $this->now->startOfDay();
        $month = $this->now->startOfMonth();

        return [
            'today' => $this->salesBetween($today, $this->now),
            'last_week' => $this->salesBetween($today->subWeek(), $this->now->subWeek()),
            'month' => $this->salesBetween($month, $this->now),
            'last_month' => $this->salesBetween($month->subMonthNoOverflow(), $this->now->subMonthNoOverflow()),
        ];
    }

    /** @return array<string, mixed> */
    public function analytics(): array
    {
        $month = [$this->now->startOfMonth(), $this->now];

        return [
            'daily' => $this->daily(14),
            'hourly' => $this->hourlyToday(),
            'channels' => $this->channels($month),
            'payments' => $this->payments($month),
            'top_products' => $this->topProducts($month, 5),
        ];
    }

    /**
     * Fila da operação agora. Só entram os indicadores das permissões recebidas.
     *
     * @param  array<string, bool>  $allowed
     * @return array<string, int|null>
     */
    public function live(array $allowed): array
    {
        $live = [];

        if ($allowed['online'] ?? false) {
            $live['online_pending'] = OnlineOrder::where('status', OnlineOrder::PENDING)->where('expires_at', '>', $this->now)->count();
        }

        if ($allowed['deliveries'] ?? false) {
            $counts = DeliveryOrder::whereIn('status', [DeliveryOrder::RECEIVED, DeliveryOrder::PREPARING, DeliveryOrder::READY, DeliveryOrder::OUT])
                ->selectRaw('status, COUNT(*) as count')->groupBy('status')->pluck('count', 'status');
            $live['producing'] = (int) ($counts[DeliveryOrder::RECEIVED] ?? 0) + (int) ($counts[DeliveryOrder::PREPARING] ?? 0);
            $live['ready'] = (int) ($counts[DeliveryOrder::READY] ?? 0);
            $live['out'] = (int) ($counts[DeliveryOrder::OUT] ?? 0);
            $live['delivery_minutes'] = $this->deliveryMinutesToday();
        }

        if ($allowed['tables'] ?? false) {
            $live['open_tables'] = TableOrder::where('status', TableOrder::OPEN)->count();
        }

        return $live;
    }

    /** @return array<string, array{count: int, total: string}> */
    public function finance(): array
    {
        $open = fn (string $type) => Bill::query()->where('type', $type)->where('status', Bill::OPEN);
        $summary = fn (Builder $query) => [
            'count' => (clone $query)->count(),
            'total' => Money::of((clone $query)->sum(DB::raw(Bill::balanceExpression()))),
        ];

        return [
            'payable_overdue' => $summary($open(Bill::PAYABLE)->whereDate('due_date', '<', $this->now)),
            'payable_week' => $summary($open(Bill::PAYABLE)->whereDate('due_date', '>=', $this->now)->whereDate('due_date', '<=', $this->now->addDays(7))),
            'receivable_open' => $summary($open(Bill::RECEIVABLE)),
            'receivable_overdue' => $summary($open(Bill::RECEIVABLE)->whereDate('due_date', '<', $this->now)),
        ];
    }

    /** @return array{count: int, items: array<int, array<string, mixed>>} */
    public function lowStock(int $limit = 5): array
    {
        return [
            'count' => Product::query()->lowStock()->count(),
            'items' => Product::query()->lowStock()->orderBy('stock_quantity')->orderBy('name')->limit($limit)
                ->get(['id', 'name', 'unit', 'stock_quantity', 'min_stock'])
                ->map(fn (Product $product) => $product->only('id', 'name', 'unit', 'stock_quantity', 'min_stock'))
                ->all(),
        ];
    }

    private function completed(CarbonImmutable $from, CarbonImmutable $to): Builder
    {
        return Sale::query()->where('sales.status', Sale::COMPLETED)->whereBetween('sales.created_at', [$from, $to]);
    }

    /** @return array{total: string, count: int, average: string} */
    private function salesBetween(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $row = $this->completed($from, $to)->selectRaw('COUNT(*) as count, SUM(total) as total')->first();
        $count = (int) $row->count;

        return [
            'total' => Money::of($row->total),
            'count' => $count,
            'average' => Money::of($count > 0 ? $row->total / $count : 0),
        ];
    }

    /** @return array<int, array{day: string, label: string, total: string, count: int}> */
    private function daily(int $days): array
    {
        $from = $this->now->startOfDay()->subDays($days - 1);
        $rows = $this->completed($from, $this->now)
            ->selectRaw('DATE(created_at) as day, SUM(total) as total, COUNT(*) as count')
            ->groupBy('day')->get()->keyBy('day');

        return collect(range(0, $days - 1))->map(function (int $offset) use ($from, $rows) {
            $day = $from->addDays($offset)->format('Y-m-d');

            return [
                'day' => $day,
                'label' => substr($day, 8, 2).'/'.substr($day, 5, 2),
                'total' => Money::of($rows[$day]->total ?? 0),
                'count' => (int) ($rows[$day]->count ?? 0),
            ];
        })->all();
    }

    /** @return array<int, array{hour: int, label: string, total: string, count: int}> */
    private function hourlyToday(): array
    {
        $hour = DB::getDriverName() === 'sqlite' ? "CAST(strftime('%H', created_at) AS INTEGER)" : 'HOUR(created_at)';
        $rows = $this->completed($this->now->startOfDay(), $this->now)
            ->selectRaw("$hour as hour, SUM(total) as total, COUNT(*) as count")
            ->groupBy('hour')->get()->keyBy('hour');

        return collect(range(0, 23))->map(fn (int $hour) => [
            'hour' => $hour,
            'label' => sprintf('%02dh', $hour),
            'total' => Money::of($rows[$hour]->total ?? 0),
            'count' => (int) ($rows[$hour]->count ?? 0),
        ])->all();
    }

    /**
     * Pedidos da loja online viram entregas: separa-os pela entrega de origem.
     *
     * @param  array{0: CarbonImmutable, 1: CarbonImmutable}  $range
     * @return array<int, array{label: string, total: string, count: int}>
     */
    private function channels(array $range): array
    {
        return $this->completed(...$range)
            ->leftJoin('delivery_orders', 'delivery_orders.sale_id', '=', 'sales.id')
            ->selectRaw("CASE WHEN delivery_orders.online_order_id IS NOT NULL THEN 'online' ELSE sales.origin END as channel, SUM(sales.total) as total, COUNT(*) as count")
            ->groupBy('channel')->orderByDesc('total')->get()
            ->map(fn ($row) => [
                'label' => self::CHANNELS[$row->channel] ?? $row->channel,
                'total' => Money::of($row->total),
                'count' => (int) $row->count,
            ])->all();
    }

    /**
     * @param  array{0: CarbonImmutable, 1: CarbonImmutable}  $range
     * @return array<int, array{label: string, total: string, count: int}>
     */
    private function payments(array $range): array
    {
        $methods = config('sales.payment_methods');

        return SalePayment::query()
            ->join('sales', 'sales.id', '=', 'sale_payments.sale_id')
            ->where('sales.status', Sale::COMPLETED)
            ->whereBetween('sales.created_at', $range)
            ->selectRaw('sale_payments.method as method, SUM(sale_payments.amount) as total, COUNT(*) as count')
            ->groupBy('sale_payments.method')->orderByDesc('total')->get()
            ->map(fn ($row) => [
                'label' => $methods[$row->method] ?? $row->method,
                'total' => Money::of($row->total),
                'count' => (int) $row->count,
            ])->all();
    }

    /**
     * @param  array{0: CarbonImmutable, 1: CarbonImmutable}  $range
     * @return array<int, array{label: string, quantity: string, total: string}>
     */
    private function topProducts(array $range, int $limit): array
    {
        return SaleItem::query()
            ->join('sales', 'sales.id', '=', 'sale_items.sale_id')
            ->where('sales.status', Sale::COMPLETED)
            ->whereBetween('sales.created_at', $range)
            ->selectRaw('sale_items.product_id, MAX(sale_items.product_name) as name, SUM(sale_items.quantity) as quantity, SUM(sale_items.total) as total')
            ->groupBy('sale_items.product_id')->orderByDesc('total')->limit($limit)->get()
            ->map(fn ($row) => [
                'label' => $row->name,
                'quantity' => rtrim(rtrim(number_format((float) $row->quantity, 3, '.', ''), '0'), '.'),
                'total' => Money::of($row->total),
            ])->all();
    }

    /** Do pedido à entrega na mão do cliente, nas entregas concluídas hoje. */
    private function deliveryMinutesToday(): ?int
    {
        $orders = DeliveryOrder::query()
            ->where('status', DeliveryOrder::DELIVERED)->where('type', DeliveryOrder::DELIVERY)
            ->whereBetween('delivered_at', [$this->now->startOfDay(), $this->now])
            ->get(['created_at', 'delivered_at']);

        return $orders->isEmpty() ? null : (int) round($orders->avg(fn (DeliveryOrder $order) => $order->created_at->diffInMinutes($order->delivered_at)));
    }
}
