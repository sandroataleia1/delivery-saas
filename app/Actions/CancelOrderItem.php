<?php

namespace App\Actions;

use App\Models\TableOrder;
use App\Models\TableOrderItem;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CancelOrderItem
{
    public function handle(TableOrderItem $item, string $reason): void
    {
        DB::transaction(function () use ($item, $reason) {
            $order = TableOrder::whereKey($item->table_order_id)->lockForUpdate()->firstOrFail();

            if (! $order->isOpen()) {
                throw ValidationException::withMessages(['reason' => 'Esta comanda já foi fechada.']);
            }

            $fresh = $item->fresh();

            if ($fresh->isCancelled()) {
                throw ValidationException::withMessages(['reason' => 'Este item já foi cancelado.']);
            }

            $fresh->forceFill([
                'cancelled_at' => now(),
                'cancelled_by' => Auth::user()->name,
                'cancel_reason' => $reason,
            ])->save();
        });
    }
}
