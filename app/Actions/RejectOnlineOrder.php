<?php

namespace App\Actions;

use App\Models\Company;
use App\Models\OnlineOrder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/** Recusa o pedido da loja online, só a partir de pendente e com motivo obrigatório. */
class RejectOnlineOrder
{
    public function handle(OnlineOrder $order, string $reason): OnlineOrder
    {
        Gate::authorize('online_orders.accept');
        $reason = trim($reason);
        Validator::make(['reason' => $reason], ['reason' => ['required', 'string', 'min:3', 'max:255']], [], ['reason' => 'motivo'])->validate();
        $user = Auth::user();

        return DB::transaction(function () use ($order, $reason, $user): OnlineOrder {
            Company::whereKey($user->company_id)->lockForUpdate()->firstOrFail();
            $locked = OnlineOrder::where('company_id', $user->company_id)->whereKey($order->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== OnlineOrder::PENDING) {
                throw ValidationException::withMessages(['online_order' => 'Este pedido não está mais aguardando aceite.']);
            }

            $locked->forceFill([
                'status' => OnlineOrder::REJECTED,
                'decided_at' => now(),
                'decided_by_id' => $user->id,
                'decided_by_name' => $user->name,
                'decision_reason' => $reason,
            ])->save();

            return $locked;
        });
    }
}
