<?php

namespace App\Actions;

use App\Models\TableOrder;
use App\Support\TableOperationLock;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Marca (ou desmarca) que a mesa pediu a conta: o caixa vê a mesa "em fechamento". */
class RequestBill
{
    public function handle(TableOrder $order, bool $requested): void
    {
        DB::transaction(function () use ($order, $requested) {
            $locked = TableOperationLock::order($order);

            if (! $locked->isOpen()) {
                throw ValidationException::withMessages(['order' => 'Esta comanda já foi fechada.']);
            }

            if ($requested && $locked->bill_requested_at === null) {
                $locked->forceFill(['bill_requested_at' => now()])->save();
            } elseif (! $requested && $locked->bill_requested_at !== null) {
                $locked->forceFill(['bill_requested_at' => null])->save();
            }
        });
    }
}
