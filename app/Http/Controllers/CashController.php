<?php

namespace App\Http\Controllers;

use App\Actions\CloseCashSession;
use App\Actions\OpenCashSession;
use App\Actions\RecordCashMovement;
use App\Models\Bill;
use App\Models\CashMovement;
use App\Models\CashSession;
use App\Models\Sale;
use App\Models\SalePayment;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class CashController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();
        $session = CashSession::query()->openFor($user)->first();
        $summary = $session?->calculateSummary();

        return Inertia::render('cash/Index', [
            'session' => $session ? [
                'id' => $session->id,
                'number' => $session->number,
                'opened_at' => $session->opened_at->format('d/m/Y H:i'),
                'opening_amount' => $session->opening_amount,
                'sales_count' => $summary['sales_count'],
                // Fechamento às cegas: o esperado só aparece para quem gerencia o caixa.
                'expected_cash' => $user->hasPermission('cash.manage') ? $summary['expected_cash'] : null,
                'movements' => $this->movements($session),
            ] : null,
        ]);
    }

    public function open(Request $request, OpenCashSession $openSession): RedirectResponse
    {
        $data = $request->validate(
            ['opening_amount' => ['required', 'numeric', 'decimal:0,2', 'min:0', 'max:9999999']],
            [],
            ['opening_amount' => 'valor inicial'],
        );

        $openSession->handle($request->user(), (string) $data['opening_amount']);

        return redirect()->route('pos')->with('success', 'Caixa aberto. Boas vendas!');
    }

    public function movement(Request $request, RecordCashMovement $recordMovement): RedirectResponse
    {
        $data = $request->validate([
            'type' => ['required', Rule::in([CashMovement::WITHDRAWAL, CashMovement::DEPOSIT])],
            'amount' => ['required', 'numeric', 'decimal:0,2', 'gt:0', 'max:9999999'],
            'reason' => ['required', 'string', 'max:255'],
        ], [], ['type' => 'tipo', 'amount' => 'valor', 'reason' => 'motivo']);

        $session = CashSession::query()->openFor($request->user())->first();

        abort_if($session === null, 409, 'Nenhum caixa aberto.');

        $recordMovement->handle($session, $data['type'], (string) $data['amount'], $data['reason']);

        return back()->with('success', __("cash.movements.{$data['type']}").' registrado.');
    }

    public function close(Request $request, CashSession $cashSession, CloseCashSession $closeSession): RedirectResponse
    {
        $user = $request->user();

        abort_unless($cashSession->user_id === $user->id || $user->hasPermission('cash.manage'), 403);

        $data = $request->validate([
            'counted_cash' => ['required', 'numeric', 'decimal:0,2', 'min:0', 'max:9999999'],
            'closing_notes' => ['nullable', 'string', 'max:255'],
        ], [], ['counted_cash' => 'dinheiro contado', 'closing_notes' => 'justificativa']);

        $closeSession->handle($cashSession, (string) $data['counted_cash'], $data['closing_notes'] ?? null);

        return redirect()->route('cash.show', $cashSession)->with('success', 'Caixa fechado.');
    }

    public function sessions(Request $request): Response
    {
        $filters = $request->validate([
            'status' => ['nullable', Rule::in([CashSession::OPEN, CashSession::CLOSED])],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
        ]);

        $user = $request->user();
        $canManage = $user->hasPermission('cash.manage');

        return Inertia::render('cash/Sessions', [
            'sessions' => CashSession::query()
                ->when(! $canManage, fn ($query) => $query->where('user_id', $user->id))
                ->when($filters['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
                ->when($filters['from'] ?? null, fn ($query, $from) => $query->where('opened_at', '>=', Carbon::parse($from)->startOfDay()))
                ->when($filters['to'] ?? null, fn ($query, $to) => $query->where('opened_at', '<=', Carbon::parse($to)->endOfDay()))
                ->latest('id')
                ->paginate(20)
                ->withQueryString()
                ->through(fn (CashSession $session) => [
                    'id' => $session->id,
                    'number' => $session->number,
                    'user' => $session->user_name,
                    'opened_at' => $session->opened_at->format('d/m/Y H:i'),
                    'closed_at' => $session->closed_at?->format('d/m/Y H:i'),
                    'is_open' => $session->isOpen(),
                    'difference' => $session->difference,
                ]),
            'filters' => $filters,
        ]);
    }

    public function show(Request $request, CashSession $cashSession): Response
    {
        $user = $request->user();
        $canManage = $user->hasPermission('cash.manage');

        abort_unless($cashSession->user_id === $user->id || $canManage, 403);

        $summary = $cashSession->summary ?? $cashSession->calculateSummary();
        $methods = config('sales.payment_methods');
        // Enquanto aberto, o operador não vê o esperado: seria o fechamento às cegas às claras.
        $revealsTotals = $canManage || ! $cashSession->isOpen();

        return Inertia::render('cash/Show', [
            'session' => [
                'id' => $cashSession->id,
                'number' => $cashSession->number,
                'user' => $cashSession->user_name,
                'is_open' => $cashSession->isOpen(),
                'opened_at' => $cashSession->opened_at->format('d/m/Y H:i'),
                'closed_at' => $cashSession->closed_at?->format('d/m/Y H:i'),
                'closed_by' => $cashSession->closed_by,
                'opening_amount' => $cashSession->opening_amount,
                'counted_cash' => $cashSession->counted_cash,
                'difference' => $cashSession->difference,
                'closing_notes' => $cashSession->closing_notes,
                'expected_cash' => $revealsTotals ? $summary['expected_cash'] : null,
                'sales_count' => $summary['sales_count'],
                'sales_total' => $revealsTotals ? $summary['sales_total'] : null,
                'deposits' => $summary['deposits'],
                'withdrawals' => $summary['withdrawals'],
                'refunds' => $summary['refunds'],
                'bill_payments' => $summary['bill_payments'] ?? '0.00',
                'bill_receipts' => $summary['bill_receipts'] ?? '0.00',
                'payments' => $revealsTotals
                    ? collect($summary['payments'])->map(fn (string $total, string $method) => [
                        'label' => $methods[$method],
                        'total' => $total,
                    ])->values()
                    : [],
                'movements' => $this->movements($cashSession),
            ],
            'canClose' => $canManage && $cashSession->isOpen(),
        ]);
    }

    /** Fluxo do dia: cada venda conta no dia em que foi feita; cada cancelamento, no dia em que aconteceu. */
    public function flow(Request $request): Response
    {
        $data = $request->validate(['date' => ['nullable', 'date_format:Y-m-d']]);
        $date = Carbon::parse($data['date'] ?? today());
        $methods = config('sales.payment_methods');

        $inflows = SalePayment::query()
            ->join('sales', 'sales.id', '=', 'sale_payments.sale_id')
            ->whereDate('sales.created_at', $date)
            ->where(fn ($query) => $query
                ->where('sales.status', Sale::COMPLETED)
                ->orWhereDate('sales.cancelled_at', '!=', $date))
            ->selectRaw('sale_payments.method as method, SUM(sale_payments.amount) as total')
            ->groupBy('sale_payments.method')
            ->pluck('total', 'method');

        // Fiado não é dinheiro recebido: entra no saldo quando a conta for paga.
        $creditSales = $inflows->pull('fiado', 0);

        $cancellations = SalePayment::query()
            ->join('sales', 'sales.id', '=', 'sale_payments.sale_id')
            ->where('sales.status', Sale::CANCELLED)
            ->whereDate('sales.cancelled_at', $date)
            ->whereDate('sales.created_at', '!=', $date)
            ->where('sale_payments.method', '!=', 'fiado')
            ->sum('sale_payments.amount');

        $movements = CashMovement::query()
            ->whereDate('created_at', $date)
            ->selectRaw('type, SUM(amount) as total')
            ->groupBy('type')
            ->pluck('total', 'type');

        $sessions = CashSession::query()
            ->where(fn ($query) => $query
                ->whereDate('opened_at', $date)
                ->orWhereDate('closed_at', $date))
            ->orderBy('number')
            ->get();

        $money = fn (mixed $value) => bcadd((string) ($value ?? '0'), '0', 2);
        $gross = $inflows->reduce(fn (string $sum, $total) => bcadd($sum, (string) $total, 2), '0.00');
        $closedToday = $sessions->filter(fn (CashSession $session) => $session->closed_at?->isSameDay($date));

        $settled = Bill::query()
            ->where('status', Bill::PAID)
            ->whereDate('paid_at', $date)
            ->selectRaw('type, SUM(paid_amount) as total')
            ->groupBy('type')
            ->pluck('total', 'type');

        $net = bcsub($gross, (string) $cancellations, 2);
        $billsPaid = $money($settled[Bill::PAYABLE] ?? 0);
        $billsReceived = $money($settled[Bill::RECEIVABLE] ?? 0);

        return Inertia::render('cash/Flow', [
            'date' => $date->format('Y-m-d'),
            'dateLabel' => $date->format('d/m/Y'),
            'today' => today()->format('Y-m-d'),
            'inflows' => collect($methods)->except('fiado')->map(fn (string $label, string $method) => [
                'label' => $label,
                'total' => $money($inflows[$method] ?? 0),
            ])->values(),
            'gross' => $gross,
            'creditSales' => $money($creditSales),
            'cancellations' => $money($cancellations),
            'net' => $net,
            'billsPaid' => $billsPaid,
            'billsReceived' => $billsReceived,
            'balance' => bcsub(bcadd($net, $billsReceived, 2), $billsPaid, 2),
            'deposits' => $money($movements[CashMovement::DEPOSIT] ?? 0),
            'withdrawals' => $money($movements[CashMovement::WITHDRAWAL] ?? 0),
            'refunds' => $money($movements[CashMovement::REFUND] ?? 0),
            'differences' => $closedToday->reduce(fn (string $sum, CashSession $session) => bcadd($sum, (string) $session->difference, 2), '0.00'),
            'sessions' => $sessions->map(fn (CashSession $session) => [
                'id' => $session->id,
                'number' => $session->number,
                'user' => $session->user_name,
                'is_open' => $session->isOpen(),
                'opened_at' => $session->opened_at->format('d/m H:i'),
                'closed_at' => $session->closed_at?->format('d/m H:i'),
                'difference' => $session->difference,
            ])->values(),
        ]);
    }

    private function movements(CashSession $session): array
    {
        return $session->movements()->latest('id')->get()->map(fn (CashMovement $movement) => [
            'id' => $movement->id,
            'type' => $movement->type,
            'label' => __("cash.movements.{$movement->type}"),
            'is_inflow' => $movement->isInflow(),
            'amount' => $movement->amount,
            'reason' => $movement->reason,
            'user' => $movement->user_name,
            'date' => $movement->created_at->format('d/m H:i'),
        ])->all();
    }
}
