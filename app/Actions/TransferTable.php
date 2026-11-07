<?php

namespace App\Actions;

use App\Models\DiningTable;
use App\Models\TableOrder;
use App\Support\Audit;
use App\Support\TableOperationLock;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class TransferTable
{
    public function handle(TableOrder $order, DiningTable $target): void
    {
        DB::transaction(function () use ($order, $target) {
            /** @var TableOrder $locked */
            $locked = TableOperationLock::order($order, [$target->id]);
            $locked->load('diningTable');
            $target = DiningTable::where('company_id', $locked->company_id)->whereKey($target->id)->where('active', true)->firstOrFail();

            if (! $locked->isOpen()) {
                throw ValidationException::withMessages(['dining_table_id' => 'Esta comanda já foi fechada.']);
            }

            if (TableOrder::where('open_table_id', $target->id)->exists()) {
                throw ValidationException::withMessages(['dining_table_id' => "A mesa {$target->number} está ocupada."]);
            }

            $from = $locked->diningTable->number;
            TableOperationLock::rotate($locked);
            $target->forceFill(['service_context' => Str::random(64)])->saveQuietly();

            $locked->forceFill(['dining_table_id' => $target->id, 'open_table_id' => $target->id])->saveQuietly();

            Audit::record('table_transferred', $locked, "Comanda #{$locked->number} transferida da mesa $from para a mesa {$target->number}");
        });
    }
}
