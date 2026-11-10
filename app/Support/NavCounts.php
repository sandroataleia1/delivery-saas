<?php

namespace App\Support;

use App\Models\MenuOrderReceipt;
use App\Models\OnlineOrder;
use App\Models\User;

/** Números ao lado dos itens do menu: pedidos esperando alguém da equipe. */
class NavCounts
{
    /** Pedidos online aguardando aceite; null para quem não aceita pedidos. */
    public static function online(?User $user): ?int
    {
        if (! $user?->company_id || ! $user->can('online_orders.accept')) {
            return null;
        }

        return OnlineOrder::where('company_id', $user->company_id)->where('status', OnlineOrder::PENDING)->where('expires_at', '>', now())->count();
    }

    /** Pedidos feitos pelo QR da mesa aguardando confirmação; null para quem não confirma. */
    public static function tables(?User $user): ?int
    {
        if (! $user?->company_id || ! $user->can('tables.confirm')) {
            return null;
        }

        return MenuOrderReceipt::where('company_id', $user->company_id)->where('status', MenuOrderReceipt::PENDING)->count();
    }
}
