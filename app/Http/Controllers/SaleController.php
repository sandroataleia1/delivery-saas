<?php

namespace App\Http\Controllers;

use App\Actions\CancelSale;
use App\Actions\RegisterSale;
use App\Http\Requests\StoreSaleRequest;
use App\Models\CashSession;
use App\Models\Category;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\SalePayment;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

class SaleController extends Controller
{
    public function pos(Request $request): Response|RedirectResponse
    {
        $user = $request->user();

        if (! $user->hasPermission('cash.operate')) {
            return redirect()->route('dashboard')->with('error', 'Seu perfil vende, mas não pode abrir caixa. Peça ajuda ao gerente.');
        }

        $session = CashSession::query()->openFor($user)->first();

        if (! $session) {
            return redirect()->route('cash.index')->with('error', 'Abra o caixa para começar a vender.');
        }

        return Inertia::render('sales/Pos', [
            'cashNumber' => $session->number,
            'products' => Product::query()
                ->where('active', true)
                ->where('sellable', true)
                ->orderBy('name')
                ->get(['id', 'name', 'sale_price', 'unit', 'category_id', 'barcode', 'sku']),
            'categories' => Category::orderBy('name')->get(['id', 'name']),
            'paymentMethods' => config('sales.payment_methods'),
            'completed' => session('completed_sale'),
        ]);
    }

    public function store(StoreSaleRequest $request, RegisterSale $registerSale): RedirectResponse
    {
        [$sale, $negative] = $registerSale->handle($request->validated());

        return redirect()->route('pos')->with('completed_sale', [
            'id' => $sale->id,
            'number' => $sale->number,
            'total' => $sale->total,
            'change' => $sale->change,
            'negative' => $negative,
        ]);
    }

    public function index(Request $request): Response
    {
        $filters = $request->validate([
            'number' => ['nullable', 'integer'],
            'status' => ['nullable', 'in:completed,cancelled'],
            'method' => ['nullable', 'string', 'max:20'],
            'user' => ['nullable', 'integer'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
        ]);

        $query = Sale::query()
            ->when($filters['number'] ?? null, fn ($query, $number) => $query->where('number', $number))
            ->when($filters['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->when($filters['method'] ?? null, fn ($query, $method) => $query->whereHas('payments', fn ($query) => $query->where('method', $method)))
            ->when($filters['user'] ?? null, fn ($query, $user) => $query->where('user_id', $user))
            ->when($filters['from'] ?? null, fn ($query, $from) => $query->where('created_at', '>=', Carbon::parse($from)->startOfDay()))
            ->when($filters['to'] ?? null, fn ($query, $to) => $query->where('created_at', '<=', Carbon::parse($to)->endOfDay()));

        $methods = config('sales.payment_methods');
        $completed = (clone $query)->where('status', Sale::COMPLETED);

        return Inertia::render('sales/Index', [
            'sales' => (clone $query)
                ->with('payments')
                ->withCount('items')
                ->latest('id')
                ->paginate(20)
                ->withQueryString()
                ->through(fn (Sale $sale) => [
                    'id' => $sale->id,
                    'number' => $sale->number,
                    'date' => $sale->created_at->format('d/m/Y H:i'),
                    'user' => $sale->user_name,
                    'items_count' => $sale->items_count,
                    'total' => $sale->total,
                    'cancelled' => $sale->isCancelled(),
                    'table_number' => $sale->table_number,
                    'origin' => $sale->origin,
                    'payments' => $sale->payments->map(fn (SalePayment $payment) => $methods[$payment->method])->implode(', '),
                ]),
            'filters' => $filters,
            'summary' => [
                'count' => (clone $completed)->count(),
                'total' => (clone $completed)->sum('total'),
                'discount' => (clone $completed)->sum('discount'),
            ],
            'methods' => $methods,
            'users' => Sale::query()->whereNotNull('user_id')->select('user_id', 'user_name')->distinct()->orderBy('user_name')->get(),
        ]);
    }

    public function show(Request $request, Sale $sale): Response
    {
        return Inertia::render('sales/Show', ['sale' => $this->present($sale, $request->user()->hasPermission('stock.view'))]);
    }

    public function receipt(Request $request, Sale $sale): Response
    {
        return Inertia::render('sales/Receipt', [
            'sale' => $this->present($sale, false),
            'company' => $request->user()->company->only(
                'legal_name', 'trade_name', 'document', 'phone', 'street', 'number', 'district', 'city', 'state',
            ),
        ]);
    }

    public function cancel(Request $request, Sale $sale, CancelSale $cancelSale): RedirectResponse
    {
        $data = $request->validate(
            ['reason' => ['required', 'string', 'max:255']],
            [],
            ['reason' => 'motivo'],
        );

        $cancelSale->handle($sale, $data['reason']);

        return back()->with('success', 'Venda cancelada e estoque devolvido.');
    }

    private function present(Sale $sale, bool $withCost): array
    {
        $sale->load(['items', 'payments', 'customer:id,name']);
        $methods = config('sales.payment_methods');

        $cost = $sale->items->reduce(
            fn (string $sum, SaleItem $item) => bcadd($sum, bcmul($item->quantity, $item->unit_cost, 4), 4),
            '0',
        );

        return [
            'id' => $sale->id,
            'number' => $sale->number,
            'date' => $sale->created_at->format('d/m/Y H:i'),
            'user' => $sale->user_name,
            'subtotal' => $sale->subtotal,
            'discount' => $sale->discount,
            'service_fee' => $sale->service_fee,
            'delivery_fee' => $sale->delivery_fee,
            'customer' => $sale->customer?->name,
            'table_number' => $sale->table_number,
            'total' => $sale->total,
            'change' => $sale->change,
            'notes' => $sale->notes,
            'cancelled' => $sale->isCancelled(),
            'cancelled_at' => $sale->cancelled_at?->format('d/m/Y H:i'),
            'cancelled_by' => $sale->cancelled_by,
            'cancel_reason' => $sale->cancel_reason,
            'cost' => $withCost ? $cost : null,
            'items' => $sale->items->map(fn (SaleItem $item) => $item->only('id', 'product_name', 'unit', 'quantity', 'unit_price', 'total')),
            'payments' => $sale->payments->map(fn (SalePayment $payment) => [
                'id' => $payment->id,
                'label' => $methods[$payment->method],
                'amount' => $payment->amount,
                // O que o cliente entregou: em dinheiro, o líquido mais o troco.
                'received' => $payment->method === 'cash' ? bcadd($payment->amount, $sale->change, 2) : $payment->amount,
            ]),
        ];
    }
}
