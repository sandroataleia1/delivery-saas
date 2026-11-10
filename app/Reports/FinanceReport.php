<?php

namespace App\Reports;

use App\Models\Bill;
use App\Models\Customer;
use App\Models\Sale;
use App\Models\SaleItem;
use Illuminate\Support\Facades\DB;

/**
 * Resultado simples por competência. A venda conta no dia em que foi feita, mesmo
 * no fiado. Compras de mercadoria ficam fora das despesas: entram como custo
 * quando vendidas, e contá-las nas duas pontas duplicaria o custo.
 */
class FinanceReport
{
    public function __construct(private Period $period) {}

    public function data(): array
    {
        $sales = Sale::query()
            ->where('status', Sale::COMPLETED)
            ->whereBetween('created_at', $this->period->range())
            ->selectRaw('SUM(subtotal) as subtotal, SUM(discount) as discount, SUM(service_fee) as service_fee, SUM(delivery_fee) as delivery_fee, SUM(total) as total')
            ->first();

        $cost = SaleItem::query()
            ->join('sales', 'sales.id', '=', 'sale_items.sale_id')
            ->where('sales.status', Sale::COMPLETED)
            ->whereBetween('sales.created_at', $this->period->range())
            ->sum(DB::raw('sale_items.quantity * sale_items.unit_cost'));

        $expenses = $this->paid(Bill::PAYABLE)
            ->whereNull('stock_entry_id')
            ->selectRaw('category, SUM(amount) as total')
            ->groupBy('category')
            ->orderByDesc('total')
            ->get();

        // Fiado recebido não é receita nova: a venda já contou no dia em que foi feita.
        $otherIncome = $this->paid(Bill::RECEIVABLE)->whereNull('sale_id')->sum('amount');
        $creditDiscounts = $this->paid(Bill::RECEIVABLE)->whereNotNull('sale_id')->sum('discount_amount');

        $expensesTotal = $expenses->sum('total');
        $grossProfit = $sales->total - $cost;
        $result = $grossProfit + $otherIncome - $expensesTotal - $creditDiscounts;

        return [
            'statement' => [
                'subtotal' => Money::of($sales->subtotal),
                'discount' => Money::of($sales->discount),
                'service_fee' => Money::of($sales->service_fee),
                'delivery_fee' => Money::of($sales->delivery_fee),
                'revenue' => Money::of($sales->total),
                'cost' => Money::of($cost),
                'gross_profit' => Money::of($grossProfit),
                'other_income' => Money::of($otherIncome),
                'credit_discounts' => Money::of($creditDiscounts),
                'expenses' => Money::of($expensesTotal),
                'result' => Money::of($result),
                'margin_percent' => $sales->total > 0 ? round(($result / $sales->total) * 100, 1) : null,
            ],
            'expenses' => $expenses->map(fn ($row) => ['label' => $row->category, 'total' => Money::of($row->total)])->all(),
            'open' => [
                'payable' => Money::of($this->open(Bill::PAYABLE)->sum(DB::raw(Bill::balanceExpression()))),
                'payable_overdue' => Money::of($this->open(Bill::PAYABLE)->overdue()->sum(DB::raw(Bill::balanceExpression()))),
                'receivable' => Money::of($this->open(Bill::RECEIVABLE)->sum(DB::raw(Bill::balanceExpression()))),
                'receivable_overdue' => Money::of($this->open(Bill::RECEIVABLE)->overdue()->sum(DB::raw(Bill::balanceExpression()))),
            ],
            'debtors' => $this->debtors(),
            'purchases' => Money::of($this->paid(Bill::PAYABLE)->whereNotNull('stock_entry_id')->sum('amount')),
        ];
    }

    private function paid(string $type)
    {
        return BillLedger::entries($this->period->from->format('Y-m-d'), $this->period->to->format('Y-m-d'))
            ->where('type', $type);
    }

    private function open(string $type)
    {
        return Bill::query()->where('type', $type)->where('status', Bill::OPEN);
    }

    private function debtors(): array
    {
        $openBills = fn ($query) => $query->where('status', Bill::OPEN);

        return Customer::query()
            ->whereHas('bills', $openBills)
            ->withSum(['bills as debt' => $openBills], DB::raw(Bill::balanceExpression()))
            ->orderByDesc('debt')
            ->limit(10)
            ->get(['id', 'name'])
            ->map(fn (Customer $customer) => ['id' => $customer->id, 'label' => $customer->name, 'total' => Money::of($customer->debt)])
            ->all();
    }
}
