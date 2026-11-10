<?php

namespace App\Http\Controllers;

use App\Actions\MoveStock;
use App\Models\Product;
use App\Models\StockMovement;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class StockController extends Controller
{
    public function index(Request $request): Response
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'low' => ['nullable', 'boolean'],
        ]);
        $tracked = Product::query()->where('track_stock', true)->where('active', true);

        return Inertia::render('stock/Index', [
            'products' => (clone $tracked)
                ->when($filters['search'] ?? null, fn ($query, $search) => $query->where(
                    fn ($query) => $query->where('name', 'like', "%$search%")
                        ->orWhere('sku', 'like', "%$search%")
                        ->orWhere('barcode', $search)
                ))
                ->when($filters['low'] ?? null, fn ($query) => $query->lowStock())
                ->orderBy('name')
                ->paginate(20)
                ->withQueryString()
                ->through(fn (Product $product) => [
                    'id' => $product->id,
                    'name' => $product->name,
                    'unit' => $product->unit,
                    'stock_quantity' => $product->stock_quantity,
                    'min_stock' => $product->min_stock,
                    'cost_price' => $product->cost_price,
                    'is_low' => $product->isLowStock(),
                    'is_negative' => $product->isNegative(),
                ]),
            'filters' => $filters,
            'summary' => [
                'products' => (clone $tracked)->count(),
                'low' => Product::query()->lowStock()->count(),
                'value' => (clone $tracked)->selectRaw('COALESCE(SUM(CASE WHEN stock_quantity > 0 THEN stock_quantity * cost_price ELSE 0 END), 0) as value')->value('value'),
            ],
        ]);
    }

    public function movements(Request $request): Response
    {
        $filters = $request->validate([
            'product' => ['nullable', 'integer'],
            'type' => ['nullable', 'string', 'max:30'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
        ]);

        return Inertia::render('stock/Movements', [
            'movements' => StockMovement::query()
                ->with(['product:id,name,unit', 'sale:id,number'])
                ->when($filters['product'] ?? null, fn ($query, $product) => $query->where('product_id', $product))
                ->when($filters['type'] ?? null, fn ($query, $type) => $query->where('type', $type))
                ->when($filters['from'] ?? null, fn ($query, $from) => $query->where('created_at', '>=', Carbon::parse($from)->startOfDay()))
                ->when($filters['to'] ?? null, fn ($query, $to) => $query->where('created_at', '<=', Carbon::parse($to)->endOfDay()))
                ->latest('id')
                ->paginate(30)
                ->withQueryString()
                ->through(fn (StockMovement $movement) => [
                    'id' => $movement->id,
                    'date' => $movement->created_at->format('d/m/Y H:i'),
                    'product' => $movement->product->name,
                    'unit' => $movement->product->unit,
                    'type' => __("stock.movements.{$movement->type}"),
                    'quantity' => $movement->quantity,
                    'balance_after' => $movement->balance_after,
                    'user' => $movement->user_name,
                    'notes' => $movement->notes,
                    'stock_entry_id' => $movement->stock_entry_id,
                    'sale_id' => $movement->sale_id,
                    'sale_number' => $movement->sale?->number,
                ]),
            'filters' => $filters,
            'types' => __('stock.movements'),
            'selectedProduct' => isset($filters['product']) ? Product::find($filters['product'])?->only('id', 'name') : null,
        ]);
    }

    public function adjust(Request $request, Product $product, MoveStock $moveStock): RedirectResponse
    {
        abort_unless($product->track_stock, 404);

        $data = $request->validate([
            'type' => ['required', Rule::in([StockMovement::ADJUSTMENT_IN, StockMovement::ADJUSTMENT_OUT, StockMovement::LOSS])],
            'quantity' => ['required', 'numeric', 'decimal:0,3', 'gt:0', 'max:999999'],
            'notes' => ['required', 'string', 'max:255'],
        ], [], ['type' => 'tipo', 'quantity' => 'quantidade', 'notes' => 'motivo']);

        $quantity = (string) $data['quantity'];

        $moveStock->handle(
            $product,
            $data['type'],
            $data['type'] === StockMovement::ADJUSTMENT_IN ? $quantity : bcmul($quantity, '-1', 3),
            notes: $data['notes'],
        );

        return back()->with('success', 'Estoque ajustado.');
    }
}
