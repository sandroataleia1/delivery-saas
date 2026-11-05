<?php

namespace App\Http\Controllers;

use App\Actions\MoveStock;
use App\Http\Requests\ProductRequest;
use App\Models\Category;
use App\Models\Product;
use App\Models\StockMovement;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class ProductController extends Controller
{
    public function index(Request $request): Response
    {
        $filters = $request->only('search', 'category', 'status');
        $canSeeCost = $request->user()->hasPermission('stock.view');

        $products = Product::query()
            ->with('category:id,name')
            ->when($filters['search'] ?? null, fn ($query, $search) => $query->where(
                fn ($query) => $query->where('name', 'like', "%$search%")
                    ->orWhere('sku', 'like', "%$search%")
                    ->orWhere('barcode', $search)
            ))
            ->when($filters['category'] ?? null, fn ($query, $category) => $query->where('category_id', $category))
            ->when($filters['status'] ?? null, fn ($query, $status) => $query->where('active', $status === 'ativos'))
            ->orderBy('name')
            ->paginate(20)
            ->withQueryString()
            ->through(fn (Product $product) => [
                'id' => $product->id,
                'name' => $product->name,
                'sku' => $product->sku,
                'barcode' => $product->barcode,
                'category' => $product->category?->name,
                'unit' => $product->unit,
                'sale_price' => $product->sale_price,
                'cost_price' => $canSeeCost ? $product->cost_price : null,
                'track_stock' => $product->track_stock,
                'stock_quantity' => $canSeeCost ? $product->stock_quantity : null,
                'active' => $product->active,
            ]);

        return Inertia::render('products/Index', [
            'products' => $products,
            'filters' => $filters,
            'categories' => Category::orderBy('name')->get(['id', 'name']),
            'canSeeCost' => $canSeeCost,
        ]);
    }

    /** Busca para o lançamento de entradas. Aceita leitor de código de barras. */
    public function search(Request $request): JsonResponse
    {
        $term = trim((string) $request->query('q'));

        if ($term === '') {
            return response()->json([]);
        }

        return response()->json(
            Product::query()
                ->where('active', true)
                ->where('track_stock', true)
                ->where(fn ($query) => $query->where('name', 'like', "%$term%")
                    ->orWhere('sku', $term)
                    ->orWhere('barcode', $term))
                ->orderByRaw('barcode = ? desc, sku = ? desc', [$term, $term])
                ->orderBy('name')
                ->limit(10)
                ->get(['id', 'name', 'unit', 'barcode', 'cost_price', 'stock_quantity'])
        );
    }

    public function create(): Response
    {
        return Inertia::render('products/Form', $this->formProps());
    }

    public function store(ProductRequest $request, MoveStock $moveStock): RedirectResponse
    {
        $data = $request->validated();

        DB::transaction(function () use ($data, $moveStock) {
            $product = new Product($data);
            $product->min_stock = $data['min_stock'] ?? 0;
            $product->cost_price = $data['cost_price'] ?? 0;
            $product->active = true;
            $product->save();

            $initialStock = (string) ($data['initial_stock'] ?? '0');

            if ($product->track_stock && bccomp($initialStock, '0', 3) > 0) {
                $moveStock->handle($product, StockMovement::INITIAL, $initialStock);
            }
        });

        return redirect()->route('products.index')->with('success', 'Produto criado.');
    }

    public function edit(Product $product): Response
    {
        return Inertia::render('products/Form', [
            ...$this->formProps(),
            'product' => $product->only(
                'id', 'name', 'category_id', 'sku', 'barcode', 'unit', 'sale_price',
                'cost_price', 'track_stock', 'min_stock', 'stock_quantity',
            ),
        ]);
    }

    public function update(ProductRequest $request, Product $product): RedirectResponse
    {
        $data = $request->validated();

        $product->fill($data);
        $product->min_stock = $data['min_stock'] ?? 0;

        // Com estoque controlado, o custo vem das entradas de mercadorias.
        if (! $product->track_stock) {
            $product->cost_price = $data['cost_price'] ?? 0;
        }

        $product->save();

        return redirect()->route('products.index')->with('success', 'Produto atualizado.');
    }

    public function toggleStatus(Product $product): RedirectResponse
    {
        $product->active = ! $product->active;
        $product->save();

        return back()->with('success', $product->active ? 'Produto ativado.' : 'Produto inativado.');
    }

    private function formProps(): array
    {
        return [
            'categories' => Category::orderBy('name')->get(['id', 'name']),
            'units' => config('stock.units'),
        ];
    }
}
