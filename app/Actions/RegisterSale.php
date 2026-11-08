<?php

namespace App\Actions;

use App\Models\Bill;
use App\Models\CashSession;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Product;
use App\Models\RecipeItem;
use App\Models\Sale;
use App\Models\StockMovement;
use App\Support\Audit;
use App\Support\Decimal;
use App\Support\ItemComposer;
use App\Support\OptionStock;
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
    public function __construct(
        private ConsumeProduct $consumeProduct,
        private CreateBills $createBills,
        private ItemComposer $composer,
        private OptionStock $optionStock,
    ) {}

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

            $items = collect($data['items'])->map(fn (array $item, int $index) => [...$item, 'original_index' => $index])->sortBy('product_id')->values();
            $products = $this->lockProducts($items->pluck('product_id'), $this->lockedSnapshots($items));

            // Compõe na ordem original do pedido, para o erro sair no índice que o cliente enviou.
            $composed = $this->composer->composeMany(
                $user->company_id,
                collect($data['items'])->filter(fn (array $item) => ! isset($item['fixed_price']))->all(),
            );

            $this->ensurePricesAreCurrent($items->whereNull('fixed_price'), $products, $composed);

            $lines = $items->map(function (array $item) use ($products, $composed) {
                $product = $products[$item['product_id']];
                $quantity = (string) $item['quantity'];
                $selection = $composed[$item['original_index']] ?? null;
                $price = $item['fixed_price'] ?? $selection['unit_price'];
                $snapshot = $selection['options'] ?? $item['options_snapshot'] ?? null;

                return [
                    'company_id' => $product->company_id,
                    'product_id' => $product->id,
                    'product_name' => $product->name,
                    'unit' => $product->unit,
                    'quantity' => $quantity,
                    'unit_price' => $price,
                    'unit_cost' => $selection['cost'] ?? ($snapshot === null ? $product->effectiveCost() : $this->optionStock->cost($product, $snapshot)),
                    'options' => $snapshot,
                    'options_label' => $selection['options_label'] ?? $item['options_label'] ?? null,
                    'total' => Decimal::money(bcmul($quantity, $price, 5)),
                ];
            });

            $subtotal = $lines->reduce(fn (string $sum, array $line) => bcadd($sum, $line['total'], 2), '0.00');
            $discount = Decimal::money((string) ($data['discount'] ?? '0'));

            if (bccomp($discount, $subtotal, 2) > 0) {
                throw ValidationException::withMessages(['discount' => 'O desconto não pode ser maior que o subtotal.']);
            }

            $afterDiscount = bcsub($subtotal, $discount, 2);
            $serviceFee = isset($data['service_percent'])
                ? Decimal::money(bcdiv(bcmul($afterDiscount, (string) $data['service_percent'], 6), '100', 6))
                : '0.00';
            $deliveryFee = bcadd((string) ($data['delivery_fee'] ?? '0'), '0', 2);
            $total = bcadd(bcadd($afterDiscount, $serviceFee, 2), $deliveryFee, 2);
            [$payments, $change] = $this->settle($data['payments'], $total);

            $customer = isset($data['customer_id']) ? Customer::whereKey($data['customer_id'])->lockForUpdate()->first() : null;
            $credit = $payments->firstWhere('method', 'fiado')['amount'] ?? null;

            if ($credit) {
                $this->ensureCreditIsAllowed($customer, $credit);
            }

            $sale = new Sale;
            $sale->forceFill([
                'company_id' => $user->company_id,
                'number' => (int) Sale::max('number') + 1,
                'client_uuid' => $data['client_uuid'],
                'cash_session_id' => $session->id,
                'origin' => $data['origin'] ?? 'counter',
                'table_number' => $data['table_number'] ?? null,
                'customer_id' => $customer?->id,
                'subtotal' => $subtotal,
                'discount' => $discount,
                'service_fee' => $serviceFee,
                'delivery_fee' => $deliveryFee,
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
                $this->consumeProduct->handle($products[$line['product_id']], $line['quantity'], sale: $sale, options: $line['options']);
            }

            if ($credit) {
                $this->createBills->handle([
                    'type' => Bill::RECEIVABLE,
                    'description' => "Venda #{$sale->number}",
                    'category' => 'Vendas a prazo',
                    'counterparty' => $customer->name,
                    'customer_id' => $customer->id,
                    'sale_id' => $sale->id,
                    'amount' => $credit,
                    'due_date' => today()->addDays($customer->credit_days)->format('Y-m-d'),
                ]);
            }

            $negative = $this->negativeBalances($sale);

            $this->audit($sale, $lines, $payments, $negative, $customer);

            return [$sale, $negative];
        });
    }

    private function ensureCreditIsAllowed(?Customer $customer, string $amount): void
    {
        if (! $customer?->allow_credit) {
            throw ValidationException::withMessages(['payments' => 'Este cliente não tem fiado liberado.']);
        }

        if ($customer->credit_limit === null) {
            return;
        }

        $available = bcsub($customer->credit_limit, $customer->openCredit(), 2);

        if (bccomp($amount, $available, 2) > 0) {
            throw ValidationException::withMessages(['payments' => 'O fiado passa do limite do cliente: disponível R$ '.Decimal::currency($available).'.']);
        }
    }

    /**
     * Composição de cada linha antes de existir o snapshot: o do pedido (valor fixo) ou os ids enviados,
     * suficientes para saber quais insumos travar.
     *
     * @return array<int, array<string, mixed>>
     */
    private function lockedSnapshots(Collection $items): array
    {
        return $items->map(fn (array $item) => isset($item['fixed_price']) ? ($item['options_snapshot'] ?? null) : [
            'size' => isset($item['size_id']) ? ['id' => (int) $item['size_id']] : null,
            'flavors' => array_map(fn ($id) => ['id' => (int) $id], $item['flavor_ids'] ?? []),
            'extras' => array_map(fn (array $row) => ['id' => (int) ($row['option_id'] ?? 0)], $item['options'] ?? []),
        ])->filter()->values()->all();
    }

    /** Trava produtos e insumos de uma vez, em ordem, para vendas simultâneas não se bloquearem. */
    private function lockProducts(Collection $productIds, array $snapshots): Collection
    {
        $ingredientIds = RecipeItem::whereIn('product_id', $productIds)->pluck('ingredient_id')->merge($this->optionStock->ingredientIds($snapshots));

        Product::query()->whereKey($productIds->merge($ingredientIds)->unique()->all())->orderBy('id')->lockForUpdate()->get();

        return Product::query()
            ->with('recipeItems.ingredient')
            ->findMany($productIds)
            ->keyBy('id');
    }

    /** @param  array<int, array{unit_price: string}>  $composed  composição por índice original do item */
    private function ensurePricesAreCurrent(Collection $items, Collection $products, array $composed): void
    {
        foreach ($items as $item) {
            $product = $products[$item['product_id']];
            $current = $composed[$item['original_index']]['unit_price'];

            if (bccomp((string) $item['expected_price'], $current, 2) !== 0) {
                throw ValidationException::withMessages([
                    'items' => "O preço de {$product->name} mudou para R$ ".Decimal::currency($current).'. Confira o carrinho.',
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
            throw ValidationException::withMessages(['payments' => 'Só dinheiro tem troco: as outras formas não podem passar do total.']);
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

    private function audit(Sale $sale, Collection $lines, Collection $payments, array $negative, ?Customer $customer): void
    {
        $methods = config('sales.payment_methods');

        Audit::record(
            'sale',
            $sale,
            "Venda #{$sale->number} de R$ ".Decimal::currency($sale->total).($sale->table_number ? " na mesa {$sale->table_number}" : ''),
            new: [
                'items' => $lines->map(fn (array $line) => Decimal::br($line['quantity'])." {$line['unit']} de {$line['product_name']} a R$ ".Decimal::currency($line['unit_price']))->all(),
                'subtotal' => Decimal::currency($sale->subtotal),
                'discount' => Decimal::currency($sale->discount),
                'service_fee' => Decimal::currency($sale->service_fee),
                'delivery_fee' => Decimal::currency($sale->delivery_fee),
                'customer' => $customer?->name,
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
