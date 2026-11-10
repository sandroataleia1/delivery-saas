<?php

namespace App\Http\Controllers;

use App\Reports\CashReport;
use App\Reports\CourierReport;
use App\Reports\Csv;
use App\Reports\DeliveriesReport;
use App\Reports\Exports;
use App\Reports\FinanceReport;
use App\Reports\Period;
use App\Reports\ProductsReport;
use App\Reports\SalesReport;
use App\Reports\StockReport;
use App\Support\Audit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReportController extends Controller
{
    private const FINANCE_ONLY = ['financeiro', 'caixa'];

    public function index(Request $request): RedirectResponse
    {
        return redirect()->route($request->user()->hasPermission('reports.view') ? 'reports.sales' : 'reports.finance');
    }

    public function sales(Request $request): Response
    {
        $period = Period::fromRequest($request);

        return $this->render('Sales', $period, (new SalesReport($period))->data());
    }

    public function products(Request $request): Response
    {
        $period = Period::fromRequest($request);
        $withCost = $request->user()->hasPermission('reports.finance');

        return $this->render('Products', $period, [...(new ProductsReport($period))->data($withCost), 'withCost' => $withCost]);
    }

    public function finance(Request $request): Response
    {
        $period = Period::fromRequest($request);

        return $this->render('Finance', $period, (new FinanceReport($period))->data());
    }

    public function stock(Request $request): Response
    {
        $period = Period::fromRequest($request);
        $withCost = $request->user()->hasPermission('reports.finance');

        return $this->render('Stock', $period, [...(new StockReport($period))->data($withCost), 'withCost' => $withCost]);
    }

    public function cash(Request $request): Response
    {
        $period = Period::fromRequest($request);

        return $this->render('Cash', $period, (new CashReport($period))->data());
    }

    public function deliveries(Request $request): Response
    {
        $period = Period::fromRequest($request);

        return $this->render('Deliveries', $period, (new DeliveriesReport($period))->data());
    }

    /** Entregas de um entregador num intervalo de dias. */
    public function couriers(Request $request): Response
    {
        $period = Period::fromRequest($request);

        return $this->render('Couriers', $period, (new CourierReport($period, $this->courierFilter($request)))->data());
    }

    public function export(Request $request, string $report): StreamedResponse
    {
        $user = $request->user();
        $needsFinance = in_array($report, self::FINANCE_ONLY, true);

        abort_unless($user->hasPermission($needsFinance ? 'reports.finance' : 'reports.view'), 403);

        $period = Period::fromRequest($request);
        $exports = new Exports($period);
        $withCost = $user->hasPermission('reports.finance');

        [$headers, $rows] = match ($report) {
            'vendas' => $exports->sales(),
            'produtos' => $exports->products($withCost),
            'financeiro' => $exports->finance(),
            'estoque' => $exports->stock($withCost),
            'caixa' => $exports->cash(),
            'entregas' => $exports->deliveries(),
            'entregadores' => $exports->courier(new CourierReport($period, $this->courierFilter($request))),
        };

        // Exportar tira dados do sistema: fica registrado quem levou o quê.
        Audit::record('report_exported', description: "Relatório de $report exportado: {$period->from->format('d/m/Y')} a {$period->to->format('d/m/Y')}");

        return Csv::download("relatorio-$report-{$period->from->format('Y-m-d')}-a-{$period->to->format('Y-m-d')}.csv", $headers, $rows);
    }

    private function courierFilter(Request $request): ?string
    {
        return $request->validate(['courier' => ['nullable', 'string', 'regex:/^[cu]\d{1,10}$/']], [], ['courier' => 'entregador'])['courier'] ?? null;
    }

    private function render(string $page, Period $period, array $data): Response
    {
        $today = today();

        return Inertia::render("reports/$page", [
            ...$data,
            'period' => [
                'from' => $period->from->format('Y-m-d'),
                'to' => $period->to->format('Y-m-d'),
                'label' => $period->from->format('d/m/Y').' a '.$period->to->format('d/m/Y'),
            ],
            'presets' => [
                ['label' => 'Hoje', 'from' => $today->format('Y-m-d'), 'to' => $today->format('Y-m-d')],
                ['label' => '7 dias', 'from' => $today->copy()->subDays(6)->format('Y-m-d'), 'to' => $today->format('Y-m-d')],
                ['label' => '30 dias', 'from' => $today->copy()->subDays(29)->format('Y-m-d'), 'to' => $today->format('Y-m-d')],
                ['label' => 'Este mês', 'from' => $today->copy()->startOfMonth()->format('Y-m-d'), 'to' => $today->format('Y-m-d')],
                ['label' => 'Mês passado', 'from' => $today->copy()->subMonthNoOverflow()->startOfMonth()->format('Y-m-d'), 'to' => $today->copy()->subMonthNoOverflow()->endOfMonth()->format('Y-m-d')],
            ],
        ]);
    }
}
