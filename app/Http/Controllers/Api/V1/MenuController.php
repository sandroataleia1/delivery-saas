<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\V1\MenuProductResource;
use App\Models\Category;
use App\Models\Product;
use App\Support\ProductComposition;
use App\Support\TableMenu;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MenuController extends Controller
{
    /** Cardápio do app: o mesmo da tela da comanda na web, só com as categorias que têm produto. */
    public function index(Request $request): JsonResponse
    {
        $products = TableMenu::products();
        $composition = ProductComposition::forProducts($products);
        $categoryIds = $products->pluck('category_id')->filter()->unique()->all();

        return response()->json([
            'categories' => Category::query()->whereIn('id', $categoryIds)->orderBy('name')->get(['id', 'name']),
            'products' => MenuProductResource::collection(
                $products->map(fn (Product $product) => ['product' => $product, 'composition' => $composition[$product->id] ?? null]),
            )->resolve($request),
        ]);
    }
}
