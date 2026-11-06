<?php

namespace App\Reports;

use App\Models\Sale;
use App\Models\SaleItem;
use Illuminate\Database\Eloquent\Builder;

class ProductsReport
{
    public function __construct(private Period $period) {}

    private function items(): Builder
    {
        return SaleItem::query()
            ->join('sales', 'sales.id', '=', 'sale_items.sale_id')
            ->where('sales.status', Sale::COMPLETED)
            ->whereBetween('sales.created_at', $this->period->range());
    }

    public function data(bool $withCost): array
    {
        $rows = $this->rows($withCost);

        return [
            'summary' => [
                'items' => count($rows),
                'quantity' => array_sum(array_column($rows, 'quantity')),
                'revenue' => Money::of(array_sum(array_column($rows, 'revenue'))),
                'cost' => $withCost ? Money::of(array_sum(array_column($rows, 'cost'))) : null,
                'without_cost' => $withCost ? count(array_filter($rows, fn (array $row) => (float) $row['cost'] === 0.0)) : null,
            ],
            'products' => array_slice($rows, 0, 50),
            'categories' => $this->categories(),
        ];
    }

    /** @return array<int, array<string, mixed>> */
    public function rows(bool $withCost): array
    {
        return $this->items()
            ->selectRaw('sale_items.product_id, MAX(sale_items.product_name) as name, MAX(sale_items.unit) as unit, SUM(sale_items.quantity) as quantity, SUM(sale_items.total) as revenue, SUM(sale_items.quantity * sale_items.unit_cost) as cost')
            ->groupBy('sale_items.product_id')
            ->orderByDesc('revenue')
            ->get()
            ->map(fn (SaleItem $row) => [
                'id' => $row->product_id,
                'name' => $row->name,
                'unit' => $row->unit,
                'quantity' => round((float) $row->quantity, 3),
                'revenue' => Money::of($row->revenue),
                'cost' => $withCost ? Money::of($row->cost) : null,
                'margin' => $withCost ? Money::of($row->revenue - $row->cost) : null,
                'margin_percent' => $withCost && $row->revenue > 0 ? round((($row->revenue - $row->cost) / $row->revenue) * 100, 1) : null,
            ])
            ->all();
    }

    private function categories(): array
    {
        return $this->items()
            ->join('products', 'products.id', '=', 'sale_items.product_id')
            ->leftJoin('categories', 'categories.id', '=', 'products.category_id')
            ->selectRaw("COALESCE(categories.name, 'Sem categoria') as name, SUM(sale_items.total) as revenue, SUM(sale_items.quantity) as quantity")
            ->groupByRaw("COALESCE(categories.name, 'Sem categoria')")
            ->orderByDesc('revenue')
            ->get()
            ->map(fn (SaleItem $row) => [
                'label' => $row->name,
                'total' => Money::of($row->revenue),
            ])
            ->all();
    }
}
