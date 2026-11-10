<?php

namespace App\Actions;

use App\Models\ProductionWork;
use App\Models\TableOrder;
use App\Models\TableOrderItem;
use App\Support\ProductionGuard;
use App\Support\TableOperationLock;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CancelOrderItem
{
    public function handle(TableOrderItem $item, string $reason): void
    {
        DB::transaction(function () use ($item, $reason) {
            $order = TableOperationLock::order(TableOrder::findOrFail($item->table_order_id));

            if (! $order->isOpen()) {
                throw ValidationException::withMessages(['reason' => 'Esta comanda já foi fechada.']);
            }

            $fresh = $item->fresh();

            if ($fresh->isCancelled()) {
                throw ValidationException::withMessages(['reason' => 'Este item já foi cancelado.']);
            }

            foreach (ProductionWork::where('table_order_item_id', $fresh->id)->orderBy('id')->lockForUpdate()->get() as $work) {
                ProductionGuard::cancel($work, $reason);
            }
            $fresh->forceFill([
                'cancelled_at' => now(),
                'cancelled_by' => Auth::user()->name,
                'cancel_reason' => $reason,
            ])->save();
        });
    }
}
