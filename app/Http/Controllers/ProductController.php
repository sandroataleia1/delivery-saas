<?php

namespace App\Http\Controllers;

use App\Actions\MarkProductSoldOut;
use App\Actions\MoveStock;
use App\Actions\SyncRecipe;
use App\Http\Requests\ProductRequest;
use App\Models\Category;
use App\Models\Company;
use App\Models\Product;
use App\Models\RecipeItem;
use App\Models\StockMovement;
use App\Support\PublicImage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class ProductController extends Controller
{
    public function index(Request $request): Response
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'category' => ['nullable', 'integer', 'min:1'],
            'status' => ['nullable', 'string', Rule::in(['ativos', 'inativos'])],
        ]);
        $canSeeCost = $request->user()->hasPermission('stock.view');

        $products = Product::query()
            ->with(['category:id,name', 'recipeItems.ingredient:id,cost_price'])
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
                'cost_price' => $canSeeCost ? $product->effectiveCost() : null,
                'has_recipe' => $product->recipeItems->isNotEmpty(),
                'track_stock' => $product->track_stock,
                'sellable' => $product->sellable,
                'stock_quantity' => $canSeeCost ? $product->stock_quantity : null,
                'active' => $product->active, 'sold_out' => $product->sold_out,
            ]);

        return Inertia::render('products/Index', [
            'products' => $products,
            'filters' => $filters,
            'categories' => Category::orderBy('name')->get(['id', 'name']),
            'canSeeCost' => $canSeeCost,
        ]);
    }

    /** Busca para entradas e fichas técnicas. Aceita leitor de código de barras. */
    public function search(Request $request): JsonResponse
    {
        abort_unless(
            $request->user()->hasPermission('entries.manage') || $request->user()->hasPermission('products.manage'),
            403,
        );

        $data = $request->validate(['q' => ['nullable', 'string', 'max:100']]);
        $term = trim($data['q'] ?? '');

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

    public function store(ProductRequest $request, MoveStock $moveStock, SyncRecipe $syncRecipe): RedirectResponse
    {
        $data = $request->validated();
        $newPath = $this->storeUploadedImage($data);

        try {
            DB::transaction(function () use ($data, $moveStock, $syncRecipe, $newPath) {
                $this->createProduct($data, $newPath, $moveStock, $syncRecipe);
            });
        } catch (\Throwable $e) {
            PublicImage::discard($newPath);

            throw $e;
        }

        return redirect()->route('products.index')->with('success', 'Produto criado.');
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function createProduct(array $data, ?string $imagePath, MoveStock $moveStock, SyncRecipe $syncRecipe): void
    {
        $product = new Product($data);
        $product->image_path = $imagePath;
        $product->min_stock = $data['min_stock'] ?? 0;
        $product->cost_price = $data['cost_price'] ?? 0;
        $product->active = true;
        $product->save();

        $initialStock = (string) ($data['initial_stock'] ?? '0');

        if ($product->track_stock && bccomp($initialStock, '0', 3) > 0) {
            $moveStock->handle($product, StockMovement::INITIAL, $initialStock);
        }
        $syncRecipe->handle($product, $data['recipe']);
    }

    public function edit(Product $product): Response
    {
        $product->load('recipeItems.ingredient:id,name,unit,cost_price,stock_quantity');

        return Inertia::render('products/Form', [
            ...$this->formProps(),
            'product' => $product->only(
                'id', 'name', 'description', 'category_id', 'sku', 'barcode', 'unit', 'sale_price',
                'cost_price', 'track_stock', 'sellable', 'sold_out', 'min_stock', 'stock_quantity',
            ) + [
                'image_url' => $product->imageUrl(),
                'recipe' => $product->recipeItems
                    ->sortBy('ingredient.name')
                    ->values()
                    ->map(fn (RecipeItem $item) => [
                        'ingredient_id' => $item->ingredient_id,
                        'name' => $item->ingredient->name,
                        'unit' => $item->ingredient->unit,
                        'cost_price' => $item->ingredient->cost_price,
                        'stock_quantity' => $item->ingredient->stock_quantity,
                        'quantity' => $item->quantity,
                    ]),
            ],
        ]);
    }

    public function update(ProductRequest $request, Product $product, SyncRecipe $syncRecipe): RedirectResponse
    {
        $data = $request->validated();
        $removeImage = (bool) ($data['remove_image'] ?? false);
        $newPath = $this->storeUploadedImage($data);

        $product->fill($data);
        $product->min_stock = $data['min_stock'] ?? 0;

        // Com estoque controlado, o custo vem das entradas de mercadorias.
        if (! $product->track_stock) {
            $product->cost_price = $data['cost_price'] ?? 0;
        }

        try {
            DB::transaction(function () use ($product, $data, $syncRecipe, $newPath, $removeImage) {
                Company::whereKey($product->company_id)->lockForUpdate()->firstOrFail();
                if ($newPath !== null || $removeImage) {
                    PublicImage::deleteAfterCommit($product->image_path);
                    $product->image_path = $newPath;
                }
                $product->save();
                $syncRecipe->handle($product, $data['recipe']);
            });
        } catch (\Throwable $e) {
            PublicImage::discard($newPath);

            throw $e;
        }

        return redirect()->route('products.index')->with('success', 'Produto atualizado.');
    }

    public function availability(Request $request, Product $product, MarkProductSoldOut $action): \Symfony\Component\HttpFoundation\Response
    {
        $data = $request->validate(['sold_out' => ['required', 'boolean']]);
        $action->handle($product, (bool) $data['sold_out']);

        return $request->expectsJson() ? response()->json(['saved' => true]) : back()->with('success', 'Disponibilidade atualizada.');
    }

    public function toggleStatus(Product $product): RedirectResponse
    {
        DB::transaction(function () use ($product) {
            Company::whereKey($product->company_id)->lockForUpdate()->firstOrFail();
            $locked = Product::whereKey($product->id)->lockForUpdate()->firstOrFail();
            $locked->active = ! $locked->active;
            $locked->save();
        });
        $product->refresh();

        return back()->with('success', $product->active ? 'Produto ativado.' : 'Produto inativado.');
    }

    /**
     * Grava a foto enviada, se houver, e tira `image`/`remove_image` dos dados do produto.
     *
     * @param  array<string, mixed>  $data
     */
    private function storeUploadedImage(array &$data): ?string
    {
        $image = $data['image'] ?? null;
        unset($data['image'], $data['remove_image']);

        return $image instanceof UploadedFile ? PublicImage::store($image, Auth::user()->company_id) : null;
    }

    private function formProps(): array
    {
        return [
            'categories' => Category::orderBy('name')->get(['id', 'name']),
            'units' => config('stock.units'),
        ];
    }
}
