<?php

namespace App\Http\Resources\V1;

use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Produto do cardápio no app. Recebe ['product' => Product, 'composition' => ?array] de ProductComposition.
 * Preço final de itens montados vem sempre da cotação do servidor; aqui é só para mostrar.
 */
class MenuProductResource extends JsonResource
{
    /**
     * @return array{id: int, name: string, category_id: ?int, price: string, unit: string, from_price: string, composition: ?array{pizza: ?array<string, mixed>, groups: array<int, array<string, mixed>>}}
     */
    public function toArray(Request $request): array
    {
        /** @var Product $product */
        $product = $this->resource['product'];
        $composition = $this->resource['composition'];

        return [
            'id' => $product->id,
            'name' => $product->name,
            'category_id' => $product->category_id,
            'price' => $product->sale_price,
            'unit' => $product->unit,
            'from_price' => $composition['from_price'] ?? $product->sale_price,
            'composition' => $composition === null ? null : [
                'pizza' => $composition['pizza'],
                'groups' => $composition['groups'],
            ],
        ];
    }
}
