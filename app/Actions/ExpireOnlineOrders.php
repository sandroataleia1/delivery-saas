<?php

namespace App\Actions;

use App\Models\Company;
use App\Models\OnlineOrder;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;

/**
 * Expira os pedidos online vencidos e ainda sem decisão. Cada pedido tem a própria transação,
 * na mesma ordem de lock do aceite: Company → pedido online.
 */
class ExpireOnlineOrders
{
    public const ACTOR = 'Sistema (expiração)';

    public function handle(CarbonImmutable $now): int
    {
        $candidates = OnlineOrder::withoutGlobalScopes()
            ->whereIn('status', [OnlineOrder::PENDING, OnlineOrder::AWAITING_PAYMENT])
            ->where('expires_at', '<=', $now)
            ->orderBy('id')
            ->get(['id', 'company_id']);

        return Context::scope(function () use ($candidates, $now): int {
            $expired = 0;

            foreach ($candidates as $candidate) {
                if ($this->expireOne($candidate->id, $candidate->company_id, $now)) {
                    $expired++;
                }
            }

            return $expired;
        }, hidden: ['audit_actor' => self::ACTOR]);
    }

    /**
     * Devolve false quando o pedido já foi decidido ou o prazo ainda não venceu.
     */
    public function expireOne(int $orderId, int $companyId, CarbonImmutable $now): bool
    {
        return DB::transaction(function () use ($orderId, $companyId, $now): bool {
            Company::whereKey($companyId)->lockForUpdate()->first();

            $order = OnlineOrder::withoutGlobalScopes()
                ->whereKey($orderId)->where('company_id', $companyId)
                ->lockForUpdate()->first();

            if (! $order
                || ! in_array($order->status, [OnlineOrder::PENDING, OnlineOrder::AWAITING_PAYMENT], true)
                || $order->expires_at->greaterThan($now)) {
                return false;
            }

            $order->forceFill([
                'status' => OnlineOrder::EXPIRED,
                'decided_at' => $now,
                'decision_reason' => AcceptOnlineOrder::EXPIRED_REASON,
            ])->save();

            return true;
        });
    }
}
