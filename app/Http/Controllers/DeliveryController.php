<?php

namespace App\Http\Controllers;

use App\Actions\AdvanceDeliveryOrder;
use App\Actions\CancelDeliveryOrder;
use App\Actions\CreateDeliveryOrder;
use App\Actions\SettleDeliveryOrder;
use App\Http\Requests\SettleDeliveryOrderRequest;
use App\Http\Requests\StoreDeliveryOrderRequest;
use App\Models\Category;
use App\Models\DeliveryOrder;
use App\Models\DeliveryOrderItem;
use App\Models\DeliveryZone;
use App\Models\Product;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class DeliveryController extends Controller
{
    /** Quadro de pedidos em andamento e entregues ainda sem acerto. */
    public function board(): Response
    {
        return Inertia::render('deliveries/Board', [
            'orders' => DeliveryOrder::query()
                ->whereNull('sale_id')
                ->where('status', '!=', DeliveryOrder::CANCELLED)
                ->orderBy('id')
                ->get()
                ->map(fn (DeliveryOrder $order) => $this->card($order)),
            'couriers' => $this->couriers(),
        ]);
    }

    public function history(Request $request): Response
    {
        $filters = $request->validate([
            'status' => ['nullable', 'string', 'max:20'],
            'courier' => ['nullable', 'integer'],
            'search' => ['nullable', 'string', 'max:100'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
        ]);

        $query = DeliveryOrder::query()
            ->when($filters['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->when($filters['courier'] ?? null, fn ($query, $courier) => $query->where('courier_id', $courier))
            ->when($filters['search'] ?? null, fn ($query, $search) => $query->where('customer_name', 'like', "%$search%"))
            ->when($filters['from'] ?? null, fn ($query, $from) => $query->where('created_at', '>=', Carbon::parse($from)->startOfDay()))
            ->when($filters['to'] ?? null, fn ($query, $to) => $query->where('created_at', '<=', Carbon::parse($to)->endOfDay()));

        return Inertia::render('deliveries/History', [
            'orders' => (clone $query)->latest('id')->paginate(20)->withQueryString()
                ->through(fn (DeliveryOrder $order) => $this->card($order)),
            'filters' => $filters,
            'summary' => [
                'count' => (clone $query)->where('status', DeliveryOrder::DELIVERED)->count(),
                'fees' => (clone $query)->where('status', DeliveryOrder::DELIVERED)->sum('delivery_fee'),
            ],
            'couriers' => $this->couriers(),
            'statuses' => __('deliveries.status'),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('deliveries/Form', [
            'products' => Product::query()->where('active', true)->where('sellable', true)->orderBy('name')
                ->get(['id', 'name', 'sale_price', 'unit', 'category_id', 'barcode', 'sku']),
            'categories' => Category::orderBy('name')->get(['id', 'name']),
            'zones' => DeliveryZone::orderBy('name')->get(['id', 'name', 'fee']),
            'paymentMethods' => config('sales.payment_methods'),
        ]);
    }

    public function store(StoreDeliveryOrderRequest $request, CreateDeliveryOrder $createOrder): RedirectResponse
    {
        $order = $createOrder->handle($request->validated());

        return redirect()->route('deliveries.show', $order)->with('success', "Pedido #{$order->number} registrado.");
    }

    public function show(Request $request, DeliveryOrder $delivery): Response
    {
        $delivery->load('items');

        return Inertia::render('deliveries/Show', [
            'order' => [
                ...$this->card($delivery),
                'phone' => $delivery->customer_phone,
                'reference' => $delivery->reference,
                'subtotal' => $delivery->subtotal,
                'delivery_fee' => $delivery->delivery_fee,
                'notes' => $delivery->notes,
                'user' => $delivery->user_name,
                'sale_id' => $delivery->sale_id,
                'cancel_reason' => $delivery->cancel_reason,
                'cancelled_by' => $delivery->cancelled_by,
                'customer_id' => $delivery->customer_id,
                'items' => $delivery->items->map(fn (DeliveryOrderItem $item) => $item->only('id', 'product_name', 'unit', 'quantity', 'unit_price', 'total', 'notes')),
            ],
            'couriers' => $this->couriers(),
            'paymentMethods' => config('sales.payment_methods'),
            'completed' => session('completed_sale'),
        ]);
    }

    public function ticket(DeliveryOrder $delivery): Response
    {
        $delivery->load('items');

        return Inertia::render('deliveries/Ticket', [
            'order' => [
                ...$this->card($delivery),
                'phone' => $delivery->customer_phone,
                'reference' => $delivery->reference,
                'subtotal' => $delivery->subtotal,
                'delivery_fee' => $delivery->delivery_fee,
                'notes' => $delivery->notes,
                'date' => $delivery->created_at->format('d/m/Y H:i'),
                'items' => $delivery->items->map(fn (DeliveryOrderItem $item) => $item->only('id', 'product_name', 'unit', 'quantity', 'total', 'notes')),
            ],
        ]);
    }

    public function advance(Request $request, DeliveryOrder $delivery, AdvanceDeliveryOrder $advance): RedirectResponse
    {
        $user = $request->user();
        $isCourierFinishing = $delivery->courier_id === $user->id && $delivery->status === DeliveryOrder::OUT;

        abort_unless($user->hasPermission('deliveries.operate') || $isCourierFinishing, 403);

        $data = $request->validate([
            'expected_status' => ['required', 'string'],
            'courier_id' => ['nullable', Rule::exists('users', 'id')->where('company_id', $user->company_id)->where('active', true)],
        ]);

        $courier = isset($data['courier_id']) ? User::with('role')->find($data['courier_id']) : null;

        if ($courier && ! $courier->hasPermission('deliveries.courier')) {
            return back()->withErrors(['courier_id' => 'Este usuário não é entregador.']);
        }

        $advance->handle($delivery, $data['expected_status'], $courier);

        return back()->with('success', 'Pedido atualizado.');
    }

    public function cancel(Request $request, DeliveryOrder $delivery, CancelDeliveryOrder $cancelOrder): RedirectResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:255']], [], ['reason' => 'motivo']);

        $cancelOrder->handle($delivery, $data['reason']);

        return back()->with('success', 'Pedido cancelado.');
    }

    public function settle(SettleDeliveryOrderRequest $request, DeliveryOrder $delivery, SettleDeliveryOrder $settleOrder): RedirectResponse
    {
        [$sale, $negative] = $settleOrder->handle($delivery, $request->validated());

        return redirect()->route('deliveries.show', $delivery)->with('completed_sale', [
            'id' => $sale->id,
            'number' => $sale->number,
            'total' => $sale->total,
            'change' => $sale->change,
            'negative' => $negative,
        ]);
    }

    /** Tela do entregador, feita para o celular. */
    public function mine(Request $request): Response
    {
        return Inertia::render('deliveries/Mine', [
            'orders' => DeliveryOrder::query()
                ->where('courier_id', $request->user()->id)
                ->where('status', DeliveryOrder::OUT)
                ->orderBy('dispatched_at')
                ->get()
                ->map(fn (DeliveryOrder $order) => [
                    ...$this->card($order),
                    'phone' => $order->customer_phone,
                    'reference' => $order->reference,
                    'notes' => $order->notes,
                ]),
            'deliveredToday' => DeliveryOrder::query()
                ->where('courier_id', $request->user()->id)
                ->where('status', DeliveryOrder::DELIVERED)
                ->whereDate('delivered_at', today())
                ->count(),
        ]);
    }

    public function zones(): Response
    {
        return Inertia::render('deliveries/Zones', [
            'zones' => DeliveryZone::orderBy('name')->get(['id', 'name', 'fee']),
        ]);
    }

    public function storeZone(Request $request): RedirectResponse
    {
        DeliveryZone::create($this->validatedZone($request));

        return back()->with('success', 'Bairro adicionado.');
    }

    public function updateZone(Request $request, DeliveryZone $zone): RedirectResponse
    {
        $zone->update($this->validatedZone($request, $zone));

        return back()->with('success', 'Bairro atualizado.');
    }

    public function destroyZone(DeliveryZone $zone): RedirectResponse
    {
        $zone->delete();

        return back()->with('success', 'Bairro excluído.');
    }

    private function validatedZone(Request $request, ?DeliveryZone $zone = null): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:100', Rule::unique('delivery_zones')->where('company_id', $request->user()->company_id)->ignore($zone)],
            'fee' => ['required', 'numeric', 'decimal:0,2', 'min:0', 'max:9999'],
        ], [], ['name' => 'bairro', 'fee' => 'taxa']);
    }

    /** @return array<int, array{id: int, name: string}> */
    private function couriers(): array
    {
        return User::query()
            ->where('active', true)
            ->with('role')
            ->orderBy('name')
            ->get()
            ->filter(fn (User $user) => $user->hasPermission('deliveries.courier'))
            ->map(fn (User $user) => $user->only('id', 'name'))
            ->values()
            ->all();
    }

    private function card(DeliveryOrder $order): array
    {
        return [
            'id' => $order->id,
            'number' => $order->number,
            'type' => $order->type,
            'type_label' => __("deliveries.types.{$order->type}"),
            'status' => $order->status,
            'status_label' => __("deliveries.status.{$order->status}"),
            'next_status' => $order->nextStatus(),
            'next_label' => $order->nextStatus() ? __("deliveries.status.{$order->nextStatus()}") : null,
            'customer' => $order->customer_name,
            'address' => $order->address(),
            'district' => $order->district,
            'total' => $order->total(),
            'payment' => config("sales.payment_methods.{$order->payment_method}"),
            'payment_method' => $order->payment_method,
            'change_for' => $order->change_for,
            'courier' => $order->courier_name,
            'settled' => $order->isSettled(),
            'minutes' => (int) $order->created_at->diffInMinutes(now()),
            'date' => $order->created_at->format('d/m/Y H:i'),
        ];
    }
}
