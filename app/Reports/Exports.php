<?php

namespace App\Reports;

use App\Models\CashSession;
use App\Models\DeliveryOrder;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SalePayment;
use Generator;

/** Linhas de cada planilha. Geradores evitam carregar tudo na memória. */
class Exports
{
    public function __construct(private Period $period) {}

    /** @return array{0: array<int, string>, 1: iterable} */
    public function sales(): array
    {
        return [
            ['Venda', 'Data', 'Origem', 'Mesa', 'Operador', 'Cliente', 'Pagamentos', 'Subtotal', 'Desconto', 'Taxa de serviço', 'Taxa de entrega', 'Total', 'Situação'],
            $this->salesRows(),
        ];
    }

    private function salesRows(): Generator
    {
        $methods = config('sales.payment_methods');
        $origins = ['counter' => 'Balcão', 'table' => 'Mesa', 'delivery' => 'Entrega'];

        $query = Sale::query()->with(['payments', 'customer:id,name'])->whereBetween('created_at', $this->period->range());

        foreach ($query->lazyById(500) as $sale) {
            yield [
                $sale->number,
                $sale->created_at->format('d/m/Y H:i'),
                $origins[$sale->origin] ?? $sale->origin,
                $sale->table_number,
                $sale->user_name,
                $sale->customer?->name,
                $sale->payments->map(fn (SalePayment $payment) => $methods[$payment->method].' '.Csv::money($payment->amount))->implode(' + '),
                Csv::money($sale->subtotal),
                Csv::money($sale->discount),
                Csv::money($sale->service_fee),
                Csv::money($sale->delivery_fee),
                Csv::money($sale->total),
                $sale->isCancelled() ? 'Cancelada' : 'Concluída',
            ];
        }
    }

    /** @return array{0: array<int, string>, 1: iterable} */
    public function products(bool $withCost): array
    {
        $headers = ['Produto', 'Unidade', 'Quantidade', 'Faturamento'];

        if ($withCost) {
            array_push($headers, 'Custo', 'Margem', 'Margem %');
        }

        $rows = array_map(fn (array $row) => array_merge(
            [$row['name'], $row['unit'], str_replace('.', ',', (string) $row['quantity']), Csv::money($row['revenue'])],
            $withCost ? [Csv::money($row['cost']), Csv::money($row['margin']), str_replace('.', ',', (string) $row['margin_percent'])] : [],
        ), (new ProductsReport($this->period))->rows($withCost));

        return [$headers, $rows];
    }

    /** @return array{0: array<int, string>, 1: iterable} */
    public function finance(): array
    {
        $data = (new FinanceReport($this->period))->data();
        $statement = $data['statement'];

        $rows = [
            ['Vendas de produtos', Csv::money($statement['subtotal'])],
            ['Taxas de serviço', Csv::money($statement['service_fee'])],
            ['Taxas de entrega', Csv::money($statement['delivery_fee'])],
            ['Descontos', Csv::money(-$statement['discount'])],
            ['Receita de vendas', Csv::money($statement['revenue'])],
            ['Custo das mercadorias vendidas', Csv::money(-$statement['cost'])],
            ['Lucro bruto', Csv::money($statement['gross_profit'])],
            ['Outras receitas', Csv::money($statement['other_income'])],
        ];

        foreach ($data['expenses'] as $expense) {
            $rows[] = ["Despesa: {$expense['label']}", Csv::money(-$expense['total'])];
        }

        $rows[] = ['Resultado do período', Csv::money($statement['result'])];

        return [['Linha', 'Valor'], $rows];
    }

    /** @return array{0: array<int, string>, 1: iterable} */
    public function stock(bool $withCost): array
    {
        $headers = ['Produto', 'Unidade', 'Saldo', 'Mínimo'];

        if ($withCost) {
            array_push($headers, 'Custo médio', 'Valor em estoque');
        }

        $rows = Product::query()->where('track_stock', true)->where('active', true)->orderBy('name')->get()
            ->map(fn (Product $product) => array_merge(
                [$product->name, $product->unit, str_replace('.', ',', $product->stock_quantity), str_replace('.', ',', $product->min_stock)],
                $withCost ? [Csv::money($product->cost_price), Csv::money(max((float) $product->stock_quantity, 0) * (float) $product->cost_price)] : [],
            ))
            ->all();

        return [$headers, $rows];
    }

    /** @return array{0: array<int, string>, 1: iterable} */
    public function cash(): array
    {
        $rows = CashSession::query()
            ->where('status', CashSession::CLOSED)
            ->whereBetween('closed_at', $this->period->range())
            ->oldest('closed_at')
            ->get()
            ->map(fn (CashSession $session) => [
                $session->number,
                $session->user_name,
                $session->opened_at->format('d/m/Y H:i'),
                $session->closed_at->format('d/m/Y H:i'),
                Csv::money($session->opening_amount),
                Csv::money($session->expected_cash),
                Csv::money($session->counted_cash),
                Csv::money($session->difference),
                $session->closing_notes,
            ])
            ->all();

        return [['Caixa', 'Operador', 'Abertura', 'Fechamento', 'Valor inicial', 'Esperado', 'Contado', 'Diferença', 'Observação'], $rows];
    }

    /** @return array{0: array<int, string>, 1: iterable} */
    public function deliveries(): array
    {
        $rows = DeliveryOrder::query()
            ->whereBetween('created_at', $this->period->range())
            ->oldest('id')
            ->get()
            ->map(fn (DeliveryOrder $order) => [
                $order->number,
                $order->created_at->format('d/m/Y H:i'),
                __("deliveries.types.{$order->type}"),
                __("deliveries.status.{$order->status}"),
                $order->customer_name,
                $order->district,
                $order->courier_name,
                Csv::money($order->subtotal),
                Csv::money($order->delivery_fee),
                Csv::money($order->total()),
            ])
            ->all();

        return [['Pedido', 'Data', 'Tipo', 'Etapa', 'Cliente', 'Bairro', 'Entregador', 'Produtos', 'Taxa', 'Total'], $rows];
    }
}
