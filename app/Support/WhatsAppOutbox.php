<?php

namespace App\Support;

use App\Jobs\SendWhatsAppMessage;
use App\Models\Company;
use App\Models\DeliveryOrder;
use App\Models\OnlineOrder;
use App\Models\OnlineStore;
use App\Models\WhatsAppMessage;
use App\Models\WhatsAppSetting;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Grava a mensagem de WhatsApp junto com a mudança de estado do pedido online, na mesma transação.
 * O envio fica para a fila, depois do commit. Nada aqui pode derrubar o pedido.
 */
class WhatsAppOutbox
{
    /** @var array<string, string> */
    private const ONLINE_EVENTS = [
        OnlineOrder::ACCEPTED => 'accepted',
        OnlineOrder::REJECTED => 'rejected',
        OnlineOrder::EXPIRED => 'expired',
    ];

    public static function register(): void
    {
        OnlineOrder::created(function (OnlineOrder $order): void {
            if ($order->status === OnlineOrder::PENDING) {
                self::record($order, 'received');
            }
        });

        OnlineOrder::updated(function (OnlineOrder $order): void {
            $event = self::ONLINE_EVENTS[$order->status] ?? null;
            if ($event !== null && $order->wasChanged('status')) {
                self::record($order, $event);
            }
        });

        DeliveryOrder::updated(function (DeliveryOrder $delivery): void {
            if ($delivery->online_order_id === null || ! $delivery->wasChanged('status')) {
                return;
            }
            $event = match ($delivery->status) {
                DeliveryOrder::PREPARING => 'preparing',
                DeliveryOrder::READY => $delivery->type === DeliveryOrder::PICKUP ? 'ready_for_pickup' : null,
                DeliveryOrder::OUT => 'out_for_delivery',
                DeliveryOrder::DELIVERED => 'delivered',
                DeliveryOrder::CANCELLED => 'cancelled',
                default => null,
            };
            if ($event === null) {
                return;
            }

            try {
                $order = OnlineOrder::withoutGlobalScopes()
                    ->whereKey($delivery->online_order_id)
                    ->where('company_id', $delivery->company_id)
                    ->first();
            } catch (Throwable $e) {
                report($e);

                return;
            }

            if ($order !== null) {
                self::record($order, $event, $delivery->cancel_reason);
            }
        });
    }

    /** Grava a linha de saída (uma por pedido e evento) e agenda o envio. Devolve o id, ou null se nada foi gravado. */
    public static function record(OnlineOrder $order, string $event, ?string $reason = null): ?int
    {
        try {
            $companyId = (int) $order->company_id;
            $settings = WhatsAppSetting::withoutGlobalScopes()->where('company_id', $companyId)->first();
            if ($settings === null || ! $settings->enabled || $settings->instance_name === null) {
                return null;
            }

            $store = OnlineStore::withoutGlobalScopes()->where('company_id', $companyId)->firstOrFail();
            $company = Company::withoutGlobalScopes()->whereKey($companyId)->firstOrFail();

            $body = WhatsAppTemplates::render($settings->templates, $event, [
                'nome' => (string) $order->customer_name,
                'loja' => (string) $company->trade_name,
                'numero' => (string) $order->number,
                'link' => route('shop.track', [$store->slug, $order->tracking_token]),
                'motivo' => match ($event) {
                    'rejected' => (string) $order->decision_reason,
                    'expired' => OnlineOrderProgress::EXPIRED_REASON,
                    'cancelled' => (string) $reason,
                    default => '',
                },
                'total' => 'R$ '.Decimal::currency($order->total),
            ]);

            $now = now();
            $inserted = DB::table('whatsapp_messages')->insertOrIgnore([
                'company_id' => $companyId,
                'online_order_id' => $order->id,
                'event' => $event,
                'phone' => '55'.$order->customer_phone,
                'body' => $body,
                'status' => WhatsAppMessage::PENDING,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            if ($inserted === 0) {
                return null;
            }

            $id = (int) WhatsAppMessage::withoutGlobalScopes()
                ->where('online_order_id', $order->id)
                ->where('event', $event)
                ->value('id');
            SendWhatsAppMessage::dispatch($id)->afterCommit();

            return $id;
        } catch (Throwable $e) {
            report($e);

            return null;
        }
    }
}
