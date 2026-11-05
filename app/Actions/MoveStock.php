<?php

namespace App\Actions;

use App\Models\Product;
use App\Models\Sale;
use App\Models\StockEntry;
use App\Models\StockMovement;
use App\Support\Decimal;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Único caminho para alterar o saldo. Trava o produto até o fim da transação,
 * para que duas operações simultâneas não leiam o mesmo saldo.
 */
class MoveStock
{
    /**
     * @param  string  $quantity  Com sinal: positivo aumenta o saldo, negativo reduz.
     * @param  string|null  $unitCost  Quando informado, recalcula o custo médio.
     * @param  bool  $allowNegative  Só para vendas: a falta de estoque não pode parar o caixa.
     */
    public function handle(
        Product $product,
        string $type,
        string $quantity,
        ?string $unitCost = null,
        ?string $notes = null,
        ?StockEntry $entry = null,
        ?Sale $sale = null,
        bool $allowNegative = false,
    ): StockMovement {
        return DB::transaction(function () use ($product, $type, $quantity, $unitCost, $notes, $entry, $sale, $allowNegative) {
            /** @var Product $locked */
            $locked = Product::withoutGlobalScopes()->whereKey($product->getKey())->lockForUpdate()->firstOrFail();

            $balance = bcadd($locked->stock_quantity, $quantity, 3);

            // Entradas nunca são barradas: mesmo sem zerar um saldo negativo, elas só o melhoram.
            if (! $allowNegative && bccomp($quantity, '0', 3) < 0 && bccomp($balance, '0', 3) < 0) {
                throw ValidationException::withMessages([
                    'stock' => "Saldo insuficiente de {$locked->name}: há ".Decimal::br($locked->stock_quantity)." {$locked->unit}.",
                ]);
            }

            if ($unitCost !== null) {
                $locked->cost_price = $this->averageCost($locked, $quantity, $unitCost, $balance);
            }

            $locked->stock_quantity = $balance;
            $locked->save();

            $user = Auth::user();
            $movement = new StockMovement;

            $movement->forceFill([
                'company_id' => $locked->company_id,
                'product_id' => $locked->id,
                'stock_entry_id' => $entry?->id,
                'sale_id' => $sale?->id,
                'type' => $type,
                'quantity' => $quantity,
                'balance_after' => $balance,
                'unit_cost' => $unitCost,
                'notes' => $notes,
                'user_id' => $user?->getKey(),
                'user_name' => $user?->name ?? 'Sistema',
            ]);
            $movement->save();

            return $movement;
        });
    }

    /** Custo médio ponderado. Serve também para desfazer uma entrada (quantidade negativa). */
    private function averageCost(Product $product, string $quantity, string $unitCost, string $balance): string
    {
        // Saldo negativo é mercadoria vendida sem registro de compra: não tem valor a ponderar.
        $base = bccomp($product->stock_quantity, '0', 3) > 0 ? $product->stock_quantity : '0';
        $remaining = bcadd($base, $quantity, 3);

        if (bccomp($balance, '0', 3) <= 0 || bccomp($remaining, '0', 3) <= 0) {
            return $product->cost_price;
        }

        $currentValue = bcmul($base, $product->cost_price, 8);
        $movedValue = bcmul($quantity, $unitCost, 8);
        $average = bcdiv(bcadd($currentValue, $movedValue, 8), $remaining, 4);

        return bccomp($average, '0', 4) < 0 ? '0.0000' : $average;
    }
}
