<?php

namespace App\Reports;

use App\Models\Bill;
use App\Models\BillSettlement;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/** Recebimentos e estornos em suas datas, com fallback para histórico anterior ao ledger. */
class BillLedger
{
    public static function entries(string $from, string $to): Builder
    {
        $base = BillSettlement::query()->join('bills', 'bills.id', '=', 'bill_settlements.bill_id')
            ->whereColumn('bills.company_id', 'bill_settlements.company_id');
        $columns = 'bills.type, bills.category, bills.sale_id, bills.stock_entry_id';
        $payments = (clone $base)->whereDate('bill_settlements.paid_at', '>=', $from)->whereDate('bill_settlements.paid_at', '<=', $to)
            ->selectRaw($columns.', bill_settlements.amount, bill_settlements.discount_amount')->toBase();
        $reversals = (clone $base)->whereDate('bill_settlements.reversed_at', '>=', $from)->whereDate('bill_settlements.reversed_at', '<=', $to)
            ->selectRaw($columns.', -bill_settlements.amount AS amount, -bill_settlements.discount_amount AS discount_amount')->toBase();
        $legacy = Bill::query()->where('status', Bill::PAID)->whereDoesntHave('settlements')
            ->whereDate('paid_at', '>=', $from)->whereDate('paid_at', '<=', $to)
            ->selectRaw($columns.', bills.paid_amount AS amount, 0 AS discount_amount')->toBase();

        return DB::query()->fromSub($payments->unionAll($reversals)->unionAll($legacy), 'bill_entries');
    }
}
