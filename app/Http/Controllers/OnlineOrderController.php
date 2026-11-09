<?php

namespace App\Http\Controllers;

use App\Actions\AcceptOnlineOrder;
use App\Actions\MarkDeliveryReady;
use App\Actions\RejectOnlineOrder;
use App\Models\DeliveryOrder;
use App\Models\OnlineOrder;
use App\Models\OnlineStore;
use App\Models\User;
use App\Models\WhatsAppConversation;
use App\Models\WhatsAppMessage;
use App\Models\WhatsAppSetting;
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
        $inProgress = OnlineOrder::with(['items', 'deliveryOrder'])
            ->where('status', OnlineOrder::ACCEPTED)
            ->whereHas('deliveryOrder', fn ($query) => $query->whereIn('status', [DeliveryOrder::RECEIVED, DeliveryOrder::PREPARING, DeliveryOrder::READY]))
            ->orderBy('decided_at')->orderBy('id')->get()
            ->partition(fn (OnlineOrder $order) => $order->deliveryOrder->status !== DeliveryOrder::READY);
        $closed = OnlineOrder::with('items')
            ->whereIn('status', [OnlineOrder::REJECTED, OnlineOrder::EXPIRED])
            ->where('decided_at', '>=', now()->subHours(12))->orderByDesc('decided_at')->orderByDesc('id')->limit(30)->get();

        $whatsapp = WhatsAppSetting::where('company_id', $company->id)->where('enabled', true)->first();

        return Inertia::render('online/Orders', [
            'pending' => $pending->map(fn (OnlineOrder $order) => $this->present($order))->values(),
            'producing' => $inProgress[0]->map(fn (OnlineOrder $order) => $this->present($order))->values(),
            'ready' => $inProgress[1]->map(fn (OnlineOrder $order) => $this->present($order))->values(),
            'closed' => $closed->map(fn (OnlineOrder $order) => $this->present($order))->values(),
            'couriers' => $request->user()->hasPermission('deliveries.operate') ? User::activeCouriers() : [],
            'store' => $store ? [
                'label' => StoreSchedule::label($store, CarbonImmutable::now()),
                'paused' => $store->isPaused(),
                'link' => route('shop.show', $store->slug),
            ] : null,
            'operation' => array_intersect_key($policy->resolve($company), array_flip(['visual_alerts', 'sound_alerts', 'late_after_minutes'])),
            'paymentMethods' => config('sales.payment_methods'),
            'whatsapp' => $whatsapp && filled($whatsapp->instance_name) ? [
                'connected' => $whatsapp->isConnected(),
                'failed_recent' => WhatsAppMessage::where('company_id', $company->id)
                    ->where('status', WhatsAppMessage::FAILED)->where('updated_at', '>=', now()->subDay())->count(),
            ] : null,
            'waitingCustomers' => WhatsAppConversation::where('company_id', $company->id)
                ->waitingForAttendant()
                ->orderBy('waiting_since')
                ->limit(20)
                ->get()
                ->map(fn (WhatsAppConversation $conversation) => [
                    'id' => $conversation->id,
                    'name' => $conversation->contact_name,
                    'phone' => $conversation->phone,
                    'since' => $conversation->waiting_since->toIso8601String(),
                    'wa_link' => $conversation->phone ? 'https://wa.me/55'.$conversation->phone : null,
                ])->values(),
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
            'delivery' => $order->relationLoaded('deliveryOrder') && $order->deliveryOrder ? [
                'id' => $order->deliveryOrder->id,
                'number' => $order->deliveryOrder->number,
                'status' => $order->deliveryOrder->status,
                'status_label' => __("deliveries.status.{$order->deliveryOrder->status}"),
                'next_status' => $order->deliveryOrder->nextStatus(),
            ] : null,
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

    /** Conclui o preparo de todos os itens do pedido, como ao soltá-lo na coluna "Prontos para entrega". */
    public function ready(OnlineOrder $onlineOrder, MarkDeliveryReady $markReady): RedirectResponse
    {
        $delivery = $onlineOrder->status === OnlineOrder::ACCEPTED ? $onlineOrder->deliveryOrder : null;

        if ($delivery === null) {
            return back()->withErrors(['online_order' => 'Aceite o pedido antes de marcá-lo como pronto.']);
        }

        $markReady->handle($delivery);

        return back()->with('success', "Pedido online #{$onlineOrder->number} pronto para entrega.");
    }

    public function reject(Request $request, OnlineOrder $onlineOrder, RejectOnlineOrder $rejectOrder): RedirectResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:255']], [], ['reason' => 'motivo']);

        $rejectOrder->handle($onlineOrder, $data['reason']);

        return back()->with('success', "Pedido online #{$onlineOrder->number} recusado.");
    }
}
