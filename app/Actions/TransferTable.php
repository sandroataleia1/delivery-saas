<?php

namespace App\Actions;

use App\Models\Company;
use App\Models\DiningTable;
use App\Models\TableOrder;
use App\Support\Audit;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class TransferTable
{
    public function handle(TableOrder $order, DiningTable $target): void
    {
        DB::transaction(function () use ($order, $target) {
            Company::whereKey($order->company_id)->lockForUpdate()->firstOrFail();

            /** @var TableOrder $locked */
            $locked = TableOrder::with('diningTable')->whereKey($order->getKey())->lockForUpdate()->firstOrFail();

            if (! $locked->isOpen()) {
                throw ValidationException::withMessages(['dining_table_id' => 'Esta comanda já foi fechada.']);
            }

            if (TableOrder::where('open_table_id', $target->id)->exists()) {
                throw ValidationException::withMessages(['dining_table_id' => "A mesa {$target->number} está ocupada."]);
            }

            $from = $locked->diningTable->number;

            $locked->forceFill(['dining_table_id' => $target->id, 'open_table_id' => $target->id])->saveQuietly();

            Audit::record('table_transferred', $locked, "Comanda #{$locked->number} transferida da mesa $from para a mesa {$target->number}");
        });
    }
}
