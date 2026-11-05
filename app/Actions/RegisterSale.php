<?php

namespace App\Actions;

use App\Models\CashSession;
use App\Models\Company;
use App\Models\Product;
use App\Models\RecipeItem;
use App\Models\Sale;
use App\Models\StockMovement;
use App\Support\Audit;
use App\Support\Decimal;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Registra a venda e baixa o estoque em uma transação. Preços e totais vêm do
 * banco: do navegador só se aceitam produto, quantidade, desconto e pagamentos.
 * A falta de estoque não impede a venda: o saldo fica negativo e gera um alerta.
 */
class RegisterSale
{
    public function __construct(private ConsumeProduct $consumeProduct) {}

    /**
     * @param  array{client_uuid: string, items: array, payments: array, discount?: ?string, notes?: ?string}  $data
     * @return array{0: Sale, 1: array<int, string>} A venda e os produtos que ficaram negativos.
     */
    public function handle(array $data): array
    {
        return DB::transaction(function () use ($data) {
            $user = Auth::user();

            // Uma venda por vez em cada empresa: garante numeração sem furos nem repetição.
            Company::whereKey($user->company_id)->lockForUpdate()->firstOrFail();

            $existing = Sale::where('client_uuid', $data['client_uuid'])->first();

            if ($existing) {
                return [$existing, []];
            }

            $session = CashSession::query()->openFor($user)->lockForUpdate()->first();

            if (! $session) {
                throw ValidationException::withMessages(['cash' => 'Seu caixa não está aberto. Abra o caixa para concluir a venda.']);
            }

            $items = collect($data['items'])->sortBy('product_id')->values();
            $products = $this->lockProducts($items->pluck('product_id'));

            $this->ensurePricesAreCurrent($items, $products);

            $lines = $items->map(function (array $item) use ($products) {
                $product = $products[$item['product_id']];
                $quantity = (string) $item['quantity'];

                return [
                    'company_id' => $product->company_id,
                    'product_id' => $product->id,
                    'product_name' => $product->name,
                    'unit' => $product->unit,
                    'quantity' => $quantity,
                    'unit_price' => $product->sale_price,
                    'unit_cost' => $product->effectiveCost(),
                    'total' => Decimal::money(bcmul($quantity, $product->sale_price, 5)),
                ];
            });

            $subtotal = $lines->reduce(fn (string $sum, array $line) => bcadd($sum, $line['total'], 2), '0.00');
            $discount = Decimal::money((string) ($data['discount'] ?? '0'));

            if (bccomp($discount, $subtotal, 2) > 0) {
                throw ValidationException::withMessages(['discount' => 'O desconto não pode ser maior que o subtotal.']);
            }

            $total = bcsub($subtotal, $discount, 2);
            [$payments, $change] = $this->settle($data['payments'], $total);

            $sale = new Sale;
            $sale->forceFill([
                'company_id' => $user->company_id,
                'number' => (int) Sale::max('number') + 1,
                'client_uuid' => $data['client_uuid'],
                'cash_session_id' => $session->id,
                'subtotal' => $subtotal,
                'discount' => $discount,
                'total' => $total,
                'change' => $change,
                'status' => Sale::COMPLETED,
                'notes' => $data['notes'] ?? null,
                'user_id' => $user->id,
                'user_name' => $user->name,
            ])->save();

            $sale->items()->createMany($lines->all());
            $sale->payments()->createMany(
                $payments->map(fn (array $payment) => [...$payment, 'company_id' => $user->company_id])->all(),
            );

            foreach ($lines as $line) {
                $this->consumeProduct->handle($products[$line['product_id']], $line['quantity'], sale: $sale);
            }

            $negative = $this->negativeBalances($sale);

            $this->audit($sale, $lines, $payments, $negative);

            return [$sale, $negative];
        });
    }

    /** Trava produtos e insumos de uma vez, em ordem, para vendas simultâneas não se bloquearem. */
    private function lockProducts(Collection $productIds): Collection
    {
        $ingredientIds = RecipeItem::whereIn('product_id', $productIds)->pluck('ingredient_id');

        Product::query()->whereKey($productIds->merge($ingredientIds)->unique()->all())->orderBy('id')->lockForUpdate()->get();

        return Product::query()
            ->with('recipeItems.ingredient')
            ->findMany($productIds)
            ->keyBy('id');
    }

    private function ensurePricesAreCurrent(Collection $items, Collection $products): void
    {
        foreach ($items as $item) {
            $product = $products[$item['product_id']];

            if (bccomp((string) $item['expected_price'], $product->sale_price, 2) !== 0) {
                throw ValidationException::withMessages([
                    'items' => "O preço de {$product->name} mudou para R$ ".Decimal::currency($product->sale_price).'. Confira o carrinho.',
                ]);
            }
        }
    }

    /**
     * Troco só existe em dinheiro: o valor gravado em cada forma é o líquido.
     *
     * @return array{0: Collection, 1: string}
     */
    private function settle(array $payments, string $total): array
    {
        $payments = collect($payments)->groupBy('method')->map(fn (Collection $group, string $method) => [
            'method' => $method,
            'amount' => $group->reduce(fn (string $sum, array $payment) => bcadd($sum, (string) $payment['amount'], 2), '0.00'),
        ])->values();

        $paid = $payments->reduce(fn (string $sum, array $payment) => bcadd($sum, $payment['amount'], 2), '0.00');
        $cash = $payments->firstWhere('method', 'cash')['amount'] ?? '0.00';
        $withoutCash = bcsub($paid, $cash, 2);

        if (bccomp($paid, $total, 2) < 0) {
            throw ValidationException::withMessages(['payments' => 'O valor pago é menor que o total da venda.']);
        }

        if (bccomp($withoutCash, $total, 2) > 0) {
            throw ValidationException::withMessages(['payments' => 'Pix, cartão e vale não podem passar do total: só dinheiro tem troco.']);
        }

        $change = bcsub($paid, $total, 2);

        $net = $payments->map(fn (array $payment) => $payment['method'] === 'cash'
            ? [...$payment, 'amount' => bcsub($payment['amount'], $change, 2)]
            : $payment)
            ->filter(fn (array $payment) => bccomp($payment['amount'], '0', 2) > 0)
            ->values();

        return [$net, $change];
    }

    /** @return array<int, string> */
    private function negativeBalances(Sale $sale): array
    {
        return StockMovement::query()
            ->where('sale_id', $sale->id)
            ->where('balance_after', '<', 0)
            ->with('product:id,name,unit')
            ->get()
            ->map(fn (StockMovement $movement) => "{$movement->product->name} (".Decimal::br($movement->balance_after)." {$movement->product->unit})")
            ->all();
    }

    private function audit(Sale $sale, Collection $lines, Collection $payments, array $negative): void
    {
        $methods = config('sales.payment_methods');

        Audit::record(
            'sale',
            $sale,
            "Venda #{$sale->number} de R$ ".Decimal::currency($sale->total),
            new: [
                'items' => $lines->map(fn (array $line) => Decimal::br($line['quantity'])." {$line['unit']} de {$line['product_name']} a R$ ".Decimal::currency($line['unit_price']))->all(),
                'subtotal' => Decimal::currency($sale->subtotal),
                'discount' => Decimal::currency($sale->discount),
                'total' => Decimal::currency($sale->total),
                'payments' => $payments->map(fn (array $payment) => "{$methods[$payment['method']]}: R$ ".Decimal::currency($payment['amount']))->all(),
                'change' => Decimal::currency($sale->change),
            ],
        );

        if ($negative !== []) {
            Audit::record('stock_negative', $sale, "Venda #{$sale->number} deixou o estoque negativo: ".implode(', ', $negative));
        }
    }
}
