<?php

namespace App\Reports;

use App\Models\Product;
use App\Models\StockMovement;
use Illuminate\Support\Facades\DB;

class StockReport
{
    public function __construct(private Period $period) {}

    public function data(bool $withCost): array
    {
        $tracked = Product::query()->where('track_stock', true)->where('active', true);

        return [
            'summary' => [
                'products' => (clone $tracked)->count(),
                'low' => Product::query()->lowStock()->count(),
                'negative' => (clone $tracked)->where('stock_quantity', '<', 0)->count(),
                'value' => $withCost
                    ? Money::of((clone $tracked)->selectRaw('SUM(CASE WHEN stock_quantity > 0 THEN stock_quantity * cost_price ELSE 0 END) as value')->value('value'))
                    : null,
                'losses' => $withCost ? Money::of($this->losses()->sum(DB::raw('ABS(stock_movements.quantity) * products.cost_price'))) : null,
            ],
            'alerts' => Product::query()
                ->lowStock()
                ->orderBy('stock_quantity')
                ->limit(30)
                ->get(['id', 'name', 'unit', 'stock_quantity', 'min_stock'])
                ->map(fn (Product $product) => $product->only('id', 'name', 'unit', 'stock_quantity', 'min_stock') + ['is_negative' => $product->isNegative()])
                ->all(),
            'losses' => $this->losses()
                ->selectRaw('products.name as name, products.unit as unit, SUM(ABS(stock_movements.quantity)) as quantity')
                ->groupBy('products.id', 'products.name', 'products.unit')
                ->orderByDesc('quantity')
                ->limit(20)
                ->get()
                ->map(fn ($row) => ['name' => $row->name, 'unit' => $row->unit, 'quantity' => round((float) $row->quantity, 3)])
                ->all(),
            'consumption' => StockMovement::query()
                ->join('products', 'products.id', '=', 'stock_movements.product_id')
                ->whereIn('stock_movements.type', [StockMovement::SALE, StockMovement::SALE_INGREDIENT])
                ->whereBetween('stock_movements.created_at', $this->period->range())
                ->selectRaw('products.name as name, products.unit as unit, SUM(ABS(stock_movements.quantity)) as quantity')
                ->groupBy('products.id', 'products.name', 'products.unit')
                ->orderByDesc('quantity')
                ->limit(20)
                ->get()
                ->map(fn ($row) => ['name' => $row->name, 'unit' => $row->unit, 'quantity' => round((float) $row->quantity, 3)])
                ->all(),
        ];
    }

    private function losses()
    {
        return StockMovement::query()
            ->join('products', 'products.id', '=', 'stock_movements.product_id')
            ->where('stock_movements.type', StockMovement::LOSS)
            ->whereBetween('stock_movements.created_at', $this->period->range());
    }
}
