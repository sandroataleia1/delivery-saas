<?php

namespace App\Reports;

use App\Models\Sale;
use App\Models\SalePayment;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class SalesReport
{
    public function __construct(private Period $period) {}

    private function completed(): Builder
    {
        return Sale::query()->where('status', Sale::COMPLETED)->whereBetween('created_at', $this->period->range());
    }

    public function data(): array
    {
        $totals = $this->completed()
            ->selectRaw('COUNT(*) as count, SUM(total) as total, SUM(discount) as discount, SUM(service_fee) as service_fee, SUM(delivery_fee) as delivery_fee')
            ->first();

        $cancelled = Sale::query()->where('status', Sale::CANCELLED)->whereBetween('created_at', $this->period->range());
        $count = (int) $totals->count;

        return [
            'summary' => [
                'count' => $count,
                'total' => Money::of($totals->total),
                'average' => Money::of($count > 0 ? $totals->total / $count : 0),
                'discount' => Money::of($totals->discount),
                'service_fee' => Money::of($totals->service_fee),
                'delivery_fee' => Money::of($totals->delivery_fee),
                'cancelled_count' => (clone $cancelled)->count(),
                'cancelled_total' => Money::of((clone $cancelled)->sum('total')),
            ],
            'daily' => $this->daily(),
            'hourly' => $this->hourly(),
            'origins' => $this->origins(),
            'payments' => $this->payments(),
            'operators' => $this->operators(),
        ];
    }

    private function daily(): array
    {
        $rows = $this->completed()
            ->selectRaw('DATE(created_at) as day, SUM(total) as total, COUNT(*) as count')
            ->groupBy('day')
            ->get()
            ->keyBy('day');

        return collect($this->period->days())->map(fn (string $day) => [
            'day' => $day,
            'label' => substr($day, 8, 2).'/'.substr($day, 5, 2),
            'total' => Money::of($rows[$day]->total ?? 0),
            'count' => (int) ($rows[$day]->count ?? 0),
        ])->all();
    }

    /** Vendas por hora do dia: mostra o horário de pico. */
    private function hourly(): array
    {
        $hour = DB::getDriverName() === 'sqlite' ? "CAST(strftime('%H', created_at) AS INTEGER)" : 'HOUR(created_at)';

        $rows = $this->completed()
            ->selectRaw("$hour as hour, SUM(total) as total, COUNT(*) as count")
            ->groupBy('hour')
            ->get()
            ->keyBy('hour');

        return collect(range(0, 23))->map(fn (int $hour) => [
            'hour' => $hour,
            'label' => sprintf('%02dh', $hour),
            'total' => Money::of($rows[$hour]->total ?? 0),
            'count' => (int) ($rows[$hour]->count ?? 0),
        ])->all();
    }

    private function origins(): array
    {
        $labels = ['counter' => 'Balcão', 'table' => 'Mesas', 'delivery' => 'Entregas'];

        return $this->completed()
            ->selectRaw('origin, SUM(total) as total, COUNT(*) as count')
            ->groupBy('origin')
            ->orderByDesc('total')
            ->get()
            ->map(fn (Sale $row) => [
                'label' => $labels[$row->origin] ?? $row->origin,
                'total' => Money::of($row->total),
                'count' => (int) $row->count,
            ])
            ->all();
    }

    private function payments(): array
    {
        $methods = config('sales.payment_methods');

        return SalePayment::query()
            ->join('sales', 'sales.id', '=', 'sale_payments.sale_id')
            ->where('sales.status', Sale::COMPLETED)
            ->whereBetween('sales.created_at', $this->period->range())
            ->selectRaw('sale_payments.method as method, SUM(sale_payments.amount) as total, COUNT(*) as count')
            ->groupBy('sale_payments.method')
            ->orderByDesc('total')
            ->get()
            ->map(fn (SalePayment $row) => [
                'label' => $methods[$row->method] ?? $row->method,
                'total' => Money::of($row->total),
                'count' => (int) $row->count,
            ])
            ->all();
    }

    private function operators(): array
    {
        return $this->completed()
            ->selectRaw('user_name, SUM(total) as total, COUNT(*) as count')
            ->groupBy('user_name')
            ->orderByDesc('total')
            ->get()
            ->map(fn (Sale $row) => [
                'label' => $row->user_name,
                'total' => Money::of($row->total),
                'count' => (int) $row->count,
            ])
            ->all();
    }
}
