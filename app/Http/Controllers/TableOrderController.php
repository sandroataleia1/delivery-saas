<?php

namespace App\Http\Controllers;

use App\Actions\AddOrderItems;
use App\Actions\CancelOrderItem;
use App\Actions\CancelTableOrder;
use App\Actions\CloseTableOrder;
use App\Actions\OpenTable;
use App\Actions\SendToKitchen;
use App\Actions\TransferTable;
use App\Http\Requests\CloseTableOrderRequest;
use App\Http\Requests\Concerns\ValidatesComposition;
use App\Models\Category;
use App\Models\DiningTable;
use App\Models\Product;
use App\Models\TableOrder;
use App\Models\TableOrderItem;
use App\Support\ProductComposition;
use App\Support\RestaurantOperationPolicy;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class TableOrderController extends Controller
{
    use ValidatesComposition;

    public function open(Request $request, DiningTable $table, OpenTable $openTable): RedirectResponse
    {
        $data = $request->validate(
            ['people' => ['required', 'integer', 'min:1', 'max:100']],
            [],
            ['people' => 'pessoas'],
        );

        $order = $openTable->handle($table, $data['people'], $request->user());

        return redirect()->route('orders.show', $order);
    }

    public function show(Request $request, TableOrder $order): Response|RedirectResponse
    {
        if (! $order->isOpen()) {
            return redirect()->route('tables.index')->with('error', 'Esta comanda já foi fechada.');
        }

        $order->load(['diningTable', 'items.production']);

        $products = Product::query()
            ->where('active', true)
            ->where('sellable', true)->where('sold_out', false)
            ->orderBy('name')
            ->get(['id', 'name', 'sale_price', 'unit', 'sold_out', 'category_id', 'barcode', 'sku']);

        return Inertia::render('tables/Order', [
            'order' => [
                'id' => $order->id,
                'number' => $order->number,
                'table' => $order->diningTable->number,
                'people' => $order->people,
                'waiter' => $order->user_name,
                'minutes' => (int) $order->opened_at->diffInMinutes(now()),
                'subtotal' => $order->subtotal(),
                'pending_kitchen' => $order->items->whereNull('cancelled_at')->whereNull('kitchen_batch')->count(),
                'items' => $order->items->map(fn (TableOrderItem $item) => [
                    'id' => $item->id,
                    'production' => $item->production?->only('id', 'status', 'version', 'delivered_at', 'legacy', 'reconciled_at'),
                    'product_name' => $item->product_name,
                    'unit' => $item->unit,
                    'quantity' => $item->quantity,
                    'unit_price' => $item->unit_price,
                    'total' => $item->total,
                    'notes' => $item->notes,
                    'sent' => $item->kitchen_batch !== null,
                    'cancelled' => $item->isCancelled(),
                    'cancel_reason' => $item->cancel_reason,
                    'user' => $item->user_name,
                ]),
            ],
            'products' => $products,
            'composition' => ProductComposition::forProducts($products),
            'categories' => Category::orderBy('name')->get(['id', 'name']),
            'paymentMethods' => config('sales.payment_methods'),
            'serviceFeePercent' => $request->user()->company->service_fee_percent,
            'freeTables' => DiningTable::query()
                ->where('active', true)
                ->whereNotIn('id', TableOrder::query()->whereNotNull('open_table_id')->select('open_table_id'))
                ->orderBy('number')
                ->get(['id', 'number']),
            'ticketBatch' => session('ticket_batch'),
            'receipts' => $order->receipts()->with('items')->orderBy('id')->get()->map(fn ($receipt) => [
                ...$receipt->only('id', 'status', 'total', 'created_at', 'decision_reason'),
                'items' => $receipt->items->map(fn ($item) => $item->only('id', 'product_name', 'quantity', 'total', 'notes')),
            ]),
            'operation' => array_intersect_key(app(RestaurantOperationPolicy::class)->resolve($request->user()->company), array_flip(['visual_alerts', 'sound_alerts', 'late_after_minutes'])),
        ]);
    }

    public function addItems(Request $request, TableOrder $order, AddOrderItems $addItems): RedirectResponse
    {
        $data = $request->validate([
            'client_uuid' => ['required', 'uuid'],
            'items' => ['required', 'array', 'min:1', 'max:100'],
            'items.*.product_id' => [
                'bail',
                'required',
                'integer',
                Rule::exists('products', 'id')->where('company_id', $request->user()->company_id)->where('active', true)->where('sellable', true),
            ],
            'items.*.quantity' => ['required', 'numeric', 'decimal:0,3', 'gt:0', 'max:9999'],
            'items.*.notes' => ['nullable', 'string', 'max:120'],
            ...$this->compositionRules('items.*'),
        ], [], ['items' => 'itens', 'items.*.product_id' => 'produto', 'items.*.quantity' => 'quantidade', 'items.*.notes' => 'observação']);

        $addItems->handle($order, $data['items'], $data['client_uuid']);

        return back()->with('success', 'Pedido lançado.');
    }

    public function kitchen(TableOrder $order, SendToKitchen $sendToKitchen): RedirectResponse
    {
        $batch = $sendToKitchen->handle($order);

        return back()->with($batch ? 'ticket_batch' : 'error', $batch ?? 'Não há itens novos para enviar.');
    }

    public function ticket(TableOrder $order, int $batch): Response
    {
        $order->load('diningTable');

        return Inertia::render('tables/Ticket', [
            'table' => $order->diningTable->number,
            'batch' => $batch,
            'date' => now()->format('d/m/Y H:i'),
            'items' => $order->activeItems()
                ->where('kitchen_batch', $batch)
                ->get()
                ->map(fn (TableOrderItem $item) => $item->only('id', 'product_name', 'unit', 'quantity', 'notes', 'user_name')),
        ]);
    }

    public function cancelItem(Request $request, TableOrder $order, TableOrderItem $item, CancelOrderItem $cancelItem): RedirectResponse
    {
        abort_unless($item->table_order_id === $order->id, 404);

        $cancelItem->handle($item, $this->reason($request));

        return back()->with('success', 'Item cancelado.');
    }

    public function cancel(Request $request, TableOrder $order, CancelTableOrder $cancelOrder): RedirectResponse
    {
        $cancelOrder->handle($order, $this->reason($request));

        return redirect()->route('tables.index')->with('success', 'Comanda cancelada e mesa liberada.');
    }

    public function transfer(Request $request, TableOrder $order, TransferTable $transferTable): RedirectResponse
    {
        $data = $request->validate([
            'dining_table_id' => [
                'required',
                Rule::exists('dining_tables', 'id')->where('company_id', $request->user()->company_id)->where('active', true),
            ],
        ], [], ['dining_table_id' => 'mesa']);

        $transferTable->handle($order, DiningTable::findOrFail($data['dining_table_id']));

        return back()->with('success', 'Comanda transferida.');
    }

    public function close(CloseTableOrderRequest $request, TableOrder $order, CloseTableOrder $closeOrder): RedirectResponse
    {
        [$sale, $negative] = $closeOrder->handle($order, $request->validated());

        return redirect()->route('tables.index')->with('completed_sale', [
            'id' => $sale->id,
            'number' => $sale->number,
            'total' => $sale->total,
            'change' => $sale->change,
            'negative' => $negative,
        ]);
    }

    private function reason(Request $request): string
    {
        return $request->validate(
            ['reason' => ['required', 'string', 'max:255']],
            [],
            ['reason' => 'motivo'],
        )['reason'];
    }
}
