<?php

namespace App\Actions;

use App\Models\Bill;
use App\Models\Sale;
use App\Models\StockEntry;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CancelBill
{
    public function handle(Bill $bill, string $reason): void
    {
        $this->cancel($bill, $reason);
    }

    /** Caminho interno: a origem deve corresponder ao vínculo e à empresa da conta. */
    public function handleFromOrigin(Bill $bill, Sale|StockEntry $origin, string $reason): void
    {
        $this->cancel($bill, $reason, $origin);
    }

    private function cancel(Bill $bill, string $reason, Sale|StockEntry|null $origin = null): void
    {
        DB::transaction(function () use ($bill, $reason, $origin) {
            /** @var Bill $locked */
            $locked = Bill::whereKey($bill->getKey())->lockForUpdate()->firstOrFail();

            $matchesOrigin = $origin !== null
                && $origin->company_id === $locked->company_id
                && ($origin instanceof Sale
                    ? $locked->sale_id === $origin->id && $locked->stock_entry_id === null
                    : $locked->stock_entry_id === $origin->id && $locked->sale_id === null);

            if (($locked->isLinked() && ! $matchesOrigin) || ($origin !== null && ! $matchesOrigin)) {
                throw ValidationException::withMessages(['reason' => 'Conta vinculada só pode ser cancelada pela operação de origem.']);
            }

            if (! $locked->isOpen() || $locked->hasActiveSettlements() || $locked->legacy_review_required) {
                throw ValidationException::withMessages(['reason' => 'Só é possível cancelar uma conta em aberto.']);
            }

            $locked->forceFill([
                'status' => Bill::CANCELLED,
                'cancelled_at' => now(),
                'cancelled_by' => Auth::user()?->name ?? 'Sistema',
                'cancel_reason' => $reason,
            ])->save();
        });
    }
}
