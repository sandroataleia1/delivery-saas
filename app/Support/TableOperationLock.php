<?php

namespace App\Support;

use App\Models\Company;
use App\Models\DiningTable;
use App\Models\MenuOrderReceipt;
use App\Models\TableOrder;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class TableOperationLock
{
    public static function table(DiningTable $table): DiningTable
    {
        Company::whereKey($table->company_id)->lockForUpdate()->firstOrFail();

        return DiningTable::withoutGlobalScopes()->where('company_id', $table->company_id)->whereKey($table->id)->lockForUpdate()->firstOrFail();
    }

    public static function order(TableOrder $order, array $additionalTables = []): TableOrder
    {
        Company::whereKey($order->company_id)->lockForUpdate()->firstOrFail();
        $current = TableOrder::whereKey($order->id)->firstOrFail();
        DiningTable::where('company_id', $current->company_id)
            ->whereIn('id', [$current->dining_table_id, ...$additionalTables])->orderBy('id')->lockForUpdate()->get();

        return TableOrder::whereKey($order->id)->lockForUpdate()->firstOrFail();
    }

    public static function assertNoPending(TableOrder $order): void
    {
        if (MenuOrderReceipt::where('table_order_id', $order->id)->where('status', 'pending')->exists()) {
            throw ValidationException::withMessages(['order' => 'Confirme ou rejeite os pedidos pendentes antes de encerrar a comanda.']);
        }
    }

    public static function rotate(TableOrder $order): void
    {
        DiningTable::whereKey($order->dining_table_id)->update(['service_context' => Str::random(64)]);
    }
}
