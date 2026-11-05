<?php

namespace App\Http\Controllers;

use App\Actions\CancelStockEntry;
use App\Actions\RegisterStockEntry;
use App\Http\Requests\StockEntryRequest;
use App\Models\StockEntry;
use App\Models\Supplier;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Inertia\Inertia;
use Inertia\Response;

class StockEntryController extends Controller
{
    public function index(Request $request): Response
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:60'],
            'supplier' => ['nullable', 'integer'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
        ]);

        return Inertia::render('entries/Index', [
            'entries' => StockEntry::query()
                ->with('supplier:id,name')
                ->withCount('items')
                ->when($filters['search'] ?? null, fn ($query, $search) => $query->where('invoice_number', 'like', "%$search%"))
                ->when($filters['supplier'] ?? null, fn ($query, $supplier) => $query->where('supplier_id', $supplier))
                ->when($filters['from'] ?? null, fn ($query, $from) => $query->whereDate('entry_date', '>=', $from))
                ->when($filters['to'] ?? null, fn ($query, $to) => $query->whereDate('entry_date', '<=', $to))
                ->latest('id')
                ->paginate(20)
                ->withQueryString()
                ->through(fn (StockEntry $entry) => [
                    'id' => $entry->id,
                    'date' => $entry->entry_date->format('d/m/Y'),
                    'supplier' => $entry->supplier?->name,
                    'invoice_number' => $entry->invoice_number,
                    'items_count' => $entry->items_count,
                    'total' => $entry->total,
                    'cancelled' => $entry->isCancelled(),
                ]),
            'filters' => $filters,
            'suppliers' => Supplier::orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('entries/Form', [
            'suppliers' => Supplier::where('active', true)->orderBy('name')->get(['id', 'name']),
            'today' => today()->format('Y-m-d'),
        ]);
    }

    public function store(StockEntryRequest $request, RegisterStockEntry $registerEntry): RedirectResponse
    {
        $data = $request->validated();

        $entry = $registerEntry->handle(Arr::except($data, 'items'), $data['items']);

        return redirect()->route('entries.show', $entry)->with('success', 'Entrada lançada e estoque atualizado.');
    }

    public function show(StockEntry $entry): Response
    {
        $entry->load(['supplier:id,name', 'items.product:id,name,unit']);

        return Inertia::render('entries/Show', [
            'entry' => [
                'id' => $entry->id,
                'date' => $entry->entry_date->format('d/m/Y'),
                'supplier' => $entry->supplier?->name,
                'invoice_number' => $entry->invoice_number,
                'notes' => $entry->notes,
                'total' => $entry->total,
                'user' => $entry->user_name,
                'created_at' => $entry->created_at->format('d/m/Y H:i'),
                'cancelled' => $entry->isCancelled(),
                'cancelled_at' => $entry->cancelled_at?->format('d/m/Y H:i'),
                'cancelled_by' => $entry->cancelled_by,
                'cancel_reason' => $entry->cancel_reason,
                'items' => $entry->items->map(fn ($item) => [
                    'id' => $item->id,
                    'product' => $item->product->name,
                    'unit' => $item->product->unit,
                    'quantity' => $item->quantity,
                    'unit_cost' => $item->unit_cost,
                    'total' => $item->total,
                ]),
            ],
        ]);
    }

    public function cancel(Request $request, StockEntry $entry, CancelStockEntry $cancelEntry): RedirectResponse
    {
        $data = $request->validate(
            ['reason' => ['required', 'string', 'max:255']],
            [],
            ['reason' => 'motivo'],
        );

        $cancelEntry->handle($entry, $data['reason']);

        return back()->with('success', 'Entrada cancelada e estoque revertido.');
    }
}
