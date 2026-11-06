<?php

namespace App\Http\Controllers;

use App\Actions\CancelBill;
use App\Actions\CreateBills;
use App\Actions\ReopenBill;
use App\Actions\SettleBill;
use App\Http\Requests\BillRequest;
use App\Models\Bill;
use App\Models\Supplier;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class BillController extends Controller
{
    private const TYPES = ['pagar' => Bill::PAYABLE, 'receber' => Bill::RECEIVABLE];

    public function index(Request $request, string $type): Response
    {
        $filters = $request->validate([
            'status' => ['nullable', Rule::in(['open', 'overdue', 'paid', 'cancelled'])],
            'search' => ['nullable', 'string', 'max:100'],
            'category' => ['nullable', 'string', 'max:40'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
        ]);

        $key = self::TYPES[$type];
        $ofType = Bill::query()->where('type', $key);

        return Inertia::render('finance/Index', [
            'type' => $type,
            'bills' => (clone $ofType)
                ->with('supplier:id,name')
                ->when($filters['status'] ?? null, fn ($query, $status) => $status === 'overdue'
                    ? $query->overdue()
                    : $query->where('status', $status))
                ->when($filters['search'] ?? null, fn ($query, $search) => $query->where(
                    fn ($query) => $query->where('description', 'like', "%$search%")->orWhere('counterparty', 'like', "%$search%")
                ))
                ->when($filters['category'] ?? null, fn ($query, $category) => $query->where('category', $category))
                ->when($filters['from'] ?? null, fn ($query, $from) => $query->whereDate('due_date', '>=', $from))
                ->when($filters['to'] ?? null, fn ($query, $to) => $query->whereDate('due_date', '<=', $to))
                ->orderByRaw("CASE WHEN status = 'open' THEN 0 ELSE 1 END")
                ->orderBy('due_date')
                ->orderBy('id')
                ->paginate(20)
                ->withQueryString()
                ->through(fn (Bill $bill) => $this->present($bill)),
            'filters' => $filters,
            'summary' => [
                'open' => (clone $ofType)->where('status', Bill::OPEN)->sum('amount'),
                'overdue' => (clone $ofType)->overdue()->sum('amount'),
                'next_days' => (clone $ofType)->where('status', Bill::OPEN)->whereBetween('due_date', [today(), today()->addDays(7)])->sum('amount'),
                'paid_month' => (clone $ofType)->where('status', Bill::PAID)->whereBetween('paid_at', [today()->startOfMonth(), today()->endOfMonth()])->sum('paid_amount'),
            ],
            'categories' => config("finance.categories.$key"),
            'methods' => config('finance.payment_methods'),
            'today' => today()->format('Y-m-d'),
        ]);
    }

    public function create(string $type): Response
    {
        return Inertia::render('finance/Form', $this->formProps($type));
    }

    public function store(BillRequest $request, string $type, CreateBills $createBills): RedirectResponse
    {
        $data = $request->validated();

        $bills = $createBills->handle(
            [...Arr::except($data, 'installments'), 'type' => self::TYPES[$type]],
            (int) ($data['installments'] ?? 1),
        );

        $message = $bills->count() > 1 ? "{$bills->count()} parcelas criadas." : 'Conta criada.';

        return redirect()->route('bills.index', $type)->with('success', $message);
    }

    public function show(Bill $bill): Response
    {
        $bill->load('supplier:id,name');

        return Inertia::render('finance/Show', [
            'bill' => $this->present($bill),
            'methods' => config('finance.payment_methods'),
            'today' => today()->format('Y-m-d'),
        ]);
    }

    public function edit(Bill $bill): Response
    {
        abort_unless($bill->isOpen(), 403);

        return Inertia::render('finance/Form', [
            ...$this->formProps($bill->isPayable() ? 'pagar' : 'receber'),
            'bill' => [
                ...$bill->only('id', 'description', 'category', 'supplier_id', 'counterparty', 'amount', 'notes'),
                'due_date' => $bill->due_date->format('Y-m-d'),
                'installment_label' => $bill->installment_total > 1 ? "Parcela {$bill->installment_number} de {$bill->installment_total}" : null,
            ],
        ]);
    }

    public function update(BillRequest $request, Bill $bill): RedirectResponse
    {
        abort_unless($bill->isOpen(), 403);

        $bill->update($request->validated());

        return redirect()->route('bills.show', $bill)->with('success', 'Conta atualizada.');
    }

    public function settle(Request $request, Bill $bill, SettleBill $settleBill): RedirectResponse
    {
        $data = $request->validate([
            'paid_at' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'paid_amount' => ['required', 'numeric', 'decimal:0,2', 'gt:0', 'max:9999999'],
            'payment_method' => ['required', Rule::in(array_keys(config('finance.payment_methods')))],
            'notes' => ['nullable', 'string', 'max:255'],
        ], [], [
            'paid_at' => 'data',
            'paid_amount' => 'valor',
            'payment_method' => 'forma de pagamento',
            'notes' => 'observações',
        ]);

        $settleBill->handle($bill, $data);

        return back()->with('success', 'Baixa registrada.');
    }

    public function reopen(Request $request, Bill $bill, ReopenBill $reopenBill): RedirectResponse
    {
        $reopenBill->handle($bill, $this->reason($request));

        return back()->with('success', 'Baixa estornada. A conta voltou a ficar em aberto.');
    }

    public function cancel(Request $request, Bill $bill, CancelBill $cancelBill): RedirectResponse
    {
        $cancelBill->handle($bill, $this->reason($request));

        return back()->with('success', 'Conta cancelada.');
    }

    private function reason(Request $request): string
    {
        return $request->validate(
            ['reason' => ['required', 'string', 'max:255']],
            [],
            ['reason' => 'motivo'],
        )['reason'];
    }

    private function formProps(string $type): array
    {
        return [
            'type' => $type,
            'categories' => config('finance.categories.'.self::TYPES[$type]),
            'suppliers' => Supplier::where('active', true)->orderBy('name')->get(['id', 'name']),
        ];
    }

    private function present(Bill $bill): array
    {
        return [
            'id' => $bill->id,
            'type' => $bill->isPayable() ? 'pagar' : 'receber',
            'description' => $bill->fullDescription(),
            'category' => $bill->category,
            'party' => $bill->supplier?->name ?? $bill->counterparty,
            'amount' => $bill->amount,
            'due_date' => $bill->due_date->format('d/m/Y'),
            'status' => $bill->statusKey(),
            'status_label' => __("finance.status.{$bill->statusKey()}"),
            'paid_at' => $bill->paid_at?->format('d/m/Y'),
            'paid_amount' => $bill->paid_amount,
            'payment_method' => $bill->payment_method ? config("finance.payment_methods.{$bill->payment_method}") : null,
            'settled_by' => $bill->settled_by,
            'notes' => $bill->notes,
            'user' => $bill->user_name,
            'stock_entry_id' => $bill->stock_entry_id,
            'cancelled_at' => $bill->cancelled_at?->format('d/m/Y H:i'),
            'cancelled_by' => $bill->cancelled_by,
            'cancel_reason' => $bill->cancel_reason,
        ];
    }
}
