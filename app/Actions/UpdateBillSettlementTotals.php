<?php

namespace App\Actions;

use App\Models\Bill;
use App\Models\BillSettlement;

/** Recalcula agregados; o chamador deve manter o lock transacional da conta. */
class UpdateBillSettlementTotals
{
    public function handle(Bill $bill): Bill
    {
        $active = $bill->settlements()->whereNull('reversed_at')->orderBy('paid_at')->orderBy('id')->get();
        $paid = $active->reduce(fn (string $total, BillSettlement $row) => bcadd($total, $row->amount, 2), '0.00');
        $discount = $active->reduce(fn (string $total, BillSettlement $row) => bcadd($total, $row->discount_amount, 2), '0.00');
        $last = $active->last();
        $bill->forceFill([
            'paid_amount' => $active->isEmpty() ? null : $paid, 'discount_amount' => $discount,
            'status' => bccomp(bcadd($paid, $discount, 2), $bill->amount, 2) === 0 ? Bill::PAID : Bill::OPEN,
            'paid_at' => $last?->paid_at, 'payment_method' => $active->count() === 1 ? $last->payment_method : null,
            'settled_by' => $last?->user_name, 'cash_movement_id' => $last?->cash_movement_id,
        ])->save();

        return $bill;
    }
}
