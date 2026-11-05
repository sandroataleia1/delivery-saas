<?php

namespace App\Actions;

use App\Models\StockEntry;
use App\Models\StockMovement;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Desfaz a entrada com lançamentos contrários. Falhando um item, nada é desfeito. */
class CancelStockEntry
{
    public function __construct(private MoveStock $moveStock) {}

    public function handle(StockEntry $entry, string $reason): void
    {
        DB::transaction(function () use ($entry, $reason) {
            /** @var StockEntry $locked */
            $locked = StockEntry::whereKey($entry->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->isCancelled()) {
                throw ValidationException::withMessages(['stock' => 'Esta entrada já foi cancelada.']);
            }

            $items = $locked->items()->with('product')->orderBy('product_id')->get();

            foreach ($items as $item) {
                $this->moveStock->handle(
                    $item->product,
                    StockMovement::ENTRY_REVERSAL,
                    bcmul($item->quantity, '-1', 3),
                    $item->unit_cost,
                    $reason,
                    $locked,
                );
            }

            $locked->forceFill([
                'status' => StockEntry::CANCELLED,
                'cancelled_at' => now(),
                'cancelled_by' => Auth::user()?->name ?? 'Sistema',
                'cancel_reason' => $reason,
            ])->save();
        });
    }
}
