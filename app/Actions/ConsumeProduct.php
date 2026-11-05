<?php

namespace App\Actions;

use App\Models\Product;
use App\Models\StockMovement;
use Illuminate\Support\Facades\DB;

/**
 * Baixa de estoque de uma venda. Produto com ficha técnica baixa os insumos;
 * os demais baixam o próprio saldo. Se faltar um insumo, nada é baixado.
 */
class ConsumeProduct
{
    public function __construct(private MoveStock $moveStock) {}

    public function handle(Product $product, string $quantity, ?string $notes = null): void
    {
        DB::transaction(function () use ($product, $quantity, $notes) {
            // Sempre na mesma ordem: duas vendas simultâneas não travam uma à outra.
            $items = $product->recipeItems()->with('ingredient')->orderBy('ingredient_id')->get();

            foreach ($items as $item) {
                $this->moveStock->handle(
                    $item->ingredient,
                    StockMovement::SALE_INGREDIENT,
                    bcmul(bcmul($item->quantity, $quantity, 6), '-1', 3),
                    notes: $notes ?? $product->name,
                );
            }

            if ($items->isEmpty() && $product->track_stock) {
                $this->moveStock->handle($product, StockMovement::SALE, bcmul($quantity, '-1', 3), notes: $notes);
            }
        });
    }
}
