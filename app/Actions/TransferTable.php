<?php

namespace App\Actions;

use App\Models\DiningTable;
use App\Models\ProductionWork;
use App\Models\TableOrder;
use App\Support\Audit;
use App\Support\ProductionGuard;
use App\Support\TableOperationLock;
use Illuminate\Support\Facades\Auth;
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

            // Quem transfere passa a ser o responsável pela mesa (recebe os avisos no modo "só o garçom da mesa").
            $locked->forceFill(['dining_table_id' => $target->id, 'open_table_id' => $target->id,
                'user_id' => Auth::id(), 'user_name' => Auth::user()->name])->saveQuietly();

            foreach (ProductionWork::where('company_id', $locked->company_id)->whereIn('table_order_item_id', $locked->items()->pluck('id'))->orderBy('id')->lockForUpdate()->get() as $work) {
                $work->forceFill(['origin_label' => 'Mesa '.$target->number.' / comanda '.$locked->number, 'version' => $work->version + 1])->save();
                ProductionGuard::event($work, $work->status, 'transferred', 'Mesa '.$from.' para '.$target->number);
            }
            Audit::record('table_transferred', $locked, "Comanda #{$locked->number} transferida da mesa $from para a mesa {$target->number}");
        });
    }
}
