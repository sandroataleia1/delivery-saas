<?php

namespace App\Actions;

use App\Models\MenuOrderReceipt;
use App\Models\TableOrder;
use App\Support\TableOperationLock;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class RejectMenuOrder
{
    public function handle(MenuOrderReceipt $receipt, string $reason): MenuOrderReceipt
    {
        Gate::authorize('tables.confirm');
        $reason = Validator::make(['reason' => $reason], ['reason' => ['required', 'string', 'max:255']])->validate()['reason'];

        return DB::transaction(function () use ($receipt, $reason) {
            $order = TableOperationLock::order(TableOrder::findOrFail($receipt->table_order_id));
            $locked = MenuOrderReceipt::whereKey($receipt->id)->where('company_id', $order->company_id)->lockForUpdate()->firstOrFail();
            if ($locked->status === 'rejected') {
                return $locked;
            }
            if ($locked->status !== 'pending') {
                throw ValidationException::withMessages(['receipt' => 'Este pedido já foi decidido.']);
            }
            $locked->forceFill(['status' => 'rejected', 'decided_at' => now(), 'decided_by_id' => Auth::id(),
                'decided_by' => Auth::user()->name, 'decision_reason' => $reason])->save();

            return $locked;
        });
    }
}
