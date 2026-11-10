<?php

namespace App\Actions;

use App\Models\Product;
use App\Models\Sale;
use App\Models\StockMovement;
use App\Support\OptionStock;
use Illuminate\Support\Facades\DB;

/**
 * Baixa de estoque de uma venda. Produto com ficha técnica baixa os insumos;
 * os demais baixam o próprio saldo. Por regra da loja, a falta de estoque não
 * impede a venda: o saldo fica negativo.
 */
class ConsumeProduct
{
    public function __construct(private MoveStock $moveStock, private OptionStock $optionStock) {}

    /**
     * @param  array<string, mixed>|null  $options  snapshot da composição. Com tamanho, a ficha base do tamanho
     *                                              substitui a ficha do produto; sabores e opções baixam as suas.
     */
    public function handle(Product $product, string $quantity, ?string $notes = null, ?Sale $sale = null, ?array $options = null): void
    {
        DB::transaction(function () use ($product, $quantity, $notes, $sale, $options) {
            $sized = ! empty($options['size']);

            // Sempre na mesma ordem: duas vendas simultâneas não travam uma à outra.
            $items = $sized ? collect() : $product->recipeItems()->with('ingredient')->orderBy('ingredient_id')->get();

            foreach ($items as $item) {
                $this->moveStock->handle(
                    $item->ingredient,
                    StockMovement::SALE_INGREDIENT,
                    bcmul(bcmul($item->quantity, $quantity, 6), '-1', 3),
                    notes: $notes ?? $product->name,
                    sale: $sale,
                    allowNegative: true,
                );
            }

            if ($options !== null) {
                $this->consumeOptions($options, $quantity, $sale);
            }

            if (! $sized && $items->isEmpty() && $product->track_stock) {
                $this->moveStock->handle($product, StockMovement::SALE, bcmul($quantity, '-1', 3), notes: $notes, sale: $sale, allowNegative: true);
            }
        });
    }

    /** @param  array<string, mixed>  $options */
    private function consumeOptions(array $options, string $quantity, ?Sale $sale): void
    {
        $consumptions = $this->optionStock->consumptions($options, $quantity);
        $ingredients = Product::withoutGlobalScopes()->whereIn('id', array_column($consumptions, 'ingredient_id'))->get()->keyBy('id');

        foreach ($consumptions as $consumption) {
            $this->moveStock->handle(
                $ingredients[$consumption['ingredient_id']],
                StockMovement::SALE_INGREDIENT,
                bcmul($consumption['quantity'], '-1', 3),
                notes: $consumption['source'],
                sale: $sale,
                allowNegative: true,
            );
        }
    }
}
