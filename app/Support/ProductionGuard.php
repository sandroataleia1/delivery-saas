<?php

namespace App\Support;

use App\Models\DeliveryOrder;
use App\Models\ProductionEvent;
use App\Models\ProductionWork;
use App\Models\TableOrder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class ProductionGuard
{
    public static function works(TableOrder|DeliveryOrder $order): Collection
    {
        $table = $order instanceof TableOrder;
        $lines = $table ? $order->activeItems() : $order->items();
        $ids = $lines->orderBy('id')->pluck('id');
        $works = ProductionWork::where('company_id', $order->company_id)->whereIn($table ? 'table_order_item_id' : 'delivery_order_item_id', $ids)->orderBy('id')->lockForUpdate()->get();
        if ($works->count() !== $ids->count()) {
            throw ValidationException::withMessages(['production' => 'Origem sem encaminhamento. Reconcilie o legado identificado antes de continuar.']);
        }

        return $works;
    }

    public static function assertKnown(TableOrder|DeliveryOrder $order): Collection
    {
        $works = self::works($order);
        if ($works->contains(fn ($w) => $w->status === null || ($w->legacy && ! $w->reconciled_at))) {
            throw ValidationException::withMessages(['production' => 'Reconcilie os itens legados antes de continuar.']);
        }

        return $works;
    }

    public static function assertDelivered(TableOrder|DeliveryOrder $order): void
    {
        if (self::assertKnown($order)->contains(fn ($w) => ! $w->delivered_at || $w->cancelled_at)) {
            throw ValidationException::withMessages(['production' => 'Todos os itens ativos precisam estar entregues antes de fechar ou acertar.']);
        }
    }

    public static function event(ProductionWork $work, ?string $from, string $to, ?string $reason = null): void
    {
        $event = new ProductionEvent;
        $event->forceFill(['company_id' => $work->company_id, 'production_work_id' => $work->id,
            'from_state' => $from, 'to_state' => $to, 'version' => $work->version,
            'actor_id' => Auth::id(), 'actor_name' => Auth::user()?->name ?? 'Cliente (QR Code)', 'reason' => $reason])->save();
    }

    public static function cancel(ProductionWork $work, string $reason): void
    {
        if ($work->cancelled_at) {
            return;
        }
        if (trim($reason) === '') {
            throw ValidationException::withMessages(['reason' => 'Informe o motivo.']);
        }
        if ($work->legacy && ! $work->reconciled_at) {
            Gate::authorize('production.manage');
        }
        if (in_array($work->status, ['preparing', 'ready'], true) || $work->delivered_at) {
            Gate::authorize('production.manage');
        }
        $from = $work->status;
        $work->forceFill(['status' => 'cancelled', 'cancelled_at' => now(), 'version' => $work->version + 1])->save();
        self::event($work, $from, 'cancelled', $reason);
    }
}
