<?php

namespace App\Actions;

use App\Models\Bill;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CancelBill
{
    public function handle(Bill $bill, string $reason): void
    {
        DB::transaction(function () use ($bill, $reason) {
            /** @var Bill $locked */
            $locked = Bill::whereKey($bill->getKey())->lockForUpdate()->firstOrFail();

            if (! $locked->isOpen()) {
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
