<?php

namespace App\Http\Controllers;

use App\Actions\AcceptOnlineOrder;
use App\Actions\RejectOnlineOrder;
use App\Models\OnlineOrder;
use App\Models\OnlineStore;
use App\Support\RestaurantOperationPolicy;
use App\Support\StoreSchedule;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class OnlineOrderController extends Controller
{
    public function index(Request $request, RestaurantOperationPolicy $policy): Response
    {
        $company = $request->user()->company;
        $store = OnlineStore::where('company_id', $company->id)->first();

        $pending = OnlineOrder::with('items')->where('status', OnlineOrder::PENDING)->where('expires_at', '>', now())
            ->orderBy('created_at')->orderBy('id')->get();
        $recent = OnlineOrder::with(['items', 'deliveryOrder:id,online_order_id'])
            ->whereIn('status', [OnlineOrder::ACCEPTED, OnlineOrder::REJECTED, OnlineOrder::EXPIRED])
            ->where('decided_at', '>=', now()->subHours(12))->orderByDesc('decided_at')->orderByDesc('id')->limit(30)->get();

        return Inertia::render('online/Orders', [
            'pending' => $pending->map(fn (OnlineOrder $order) => $this->present($order))->values(),
            'recent' => $recent->map(fn (OnlineOrder $order) => $this->present($order))->values(),
            'store' => $store ? [
                'label' => StoreSchedule::label($store, CarbonImmutable::now()),
                'paused' => $store->isPaused(),
                'link' => route('shop.show', $store->slug),
            ] : null,
            'operation' => array_intersect_key($policy->resolve($company), array_flip(['visual_alerts', 'sound_alerts', 'late_after_minutes'])),
            'paymentMethods' => config('sales.payment_methods'),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(OnlineOrder $order): array
    {
        return [
            ...$order->only('id', 'number', 'type', 'customer_name', 'customer_phone', 'street', 'number_address', 'complement', 'reference',
                'zone_name', 'subtotal', 'delivery_fee', 'total', 'payment_method', 'change_for', 'notes', 'status', 'decided_by_name', 'decision_reason'),
            'created_at' => $order->created_at?->toIso8601String(),
            'expires_at' => $order->expires_at?->toIso8601String(),
            'decided_at' => $order->decided_at?->toIso8601String(),
            'delivery_id' => $order->relationLoaded('deliveryOrder') ? $order->deliveryOrder?->id : null,
            'items' => $order->items->map(fn ($item) => [
                'id' => $item->id,
                'product_name' => $item->product_name,
                'quantity' => $item->quantity,
                'total' => $item->total,
                'options_label' => $item->options_label,
                'notes' => $item->notes,
            ])->values(),
        ];
    }

    public function accept(OnlineOrder $onlineOrder, AcceptOnlineOrder $acceptOrder): RedirectResponse
    {
        $delivery = $acceptOrder->handle($onlineOrder);

        return back()->with('success', "Pedido online #{$onlineOrder->number} aceito como entrega #{$delivery->number}.");
    }

    public function reject(Request $request, OnlineOrder $onlineOrder, RejectOnlineOrder $rejectOrder): RedirectResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:255']], [], ['reason' => 'motivo']);

        $rejectOrder->handle($onlineOrder, $data['reason']);

        return back()->with('success', "Pedido online #{$onlineOrder->number} recusado.");
    }
}
