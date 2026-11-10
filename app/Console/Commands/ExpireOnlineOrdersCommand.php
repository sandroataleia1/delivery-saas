<?php

namespace App\Console\Commands;

use App\Actions\ExpireOnlineOrders;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('online-orders:expire')]
#[Description('Expira os pedidos online cujo prazo de aceite venceu')]
class ExpireOnlineOrdersCommand extends Command
{
    public function handle(ExpireOnlineOrders $action): int
    {
        $expired = $action->handle(CarbonImmutable::now());

        $this->info("Pedidos online expirados: {$expired}");

        return self::SUCCESS;
    }
}
