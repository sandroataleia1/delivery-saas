<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\ConfirmMenuOrder;
use App\Actions\RejectMenuOrder;
use App\Http\Controllers\Controller;
use App\Http\Resources\V1\TableOrderResource;
use App\Models\MenuOrderReceipt;
use App\Models\TableOrder;
use Illuminate\Http\Request;

/** Pedido feito pelo QR da mesa, confirmado ou recusado pelo garçom no app. Devolve a comanda atualizada. */
class MenuReceiptController extends Controller
{
    public function confirm(MenuOrderReceipt $receipt, ConfirmMenuOrder $action): TableOrderResource
    {
        $action->handle($receipt);

        return TableOrderResource::make(TableOrder::findOrFail($receipt->table_order_id));
    }

    public function reject(Request $request, MenuOrderReceipt $receipt, RejectMenuOrder $action): TableOrderResource
    {
        $reason = $request->validate(['reason' => ['required', 'string', 'max:255']], [], ['reason' => 'motivo'])['reason'];

        $action->handle($receipt, $reason);

        return TableOrderResource::make(TableOrder::findOrFail($receipt->table_order_id));
    }
}
