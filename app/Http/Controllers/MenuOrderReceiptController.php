<?php

namespace App\Http\Controllers;

use App\Actions\ConfirmMenuOrder;
use App\Actions\RejectMenuOrder;
use App\Models\MenuOrderReceipt;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class MenuOrderReceiptController extends Controller
{
    public function confirm(Request $request, MenuOrderReceipt $receipt, ConfirmMenuOrder $action): Response
    {
        $action->handle($receipt);

        return $request->expectsJson() ? response()->json(['saved' => true]) : back()->with('success', 'Pedido confirmado. Aguarda envio manual pela equipe.');
    }

    public function reject(Request $request, MenuOrderReceipt $receipt, RejectMenuOrder $action): Response
    {
        $reason = $request->validate(['reason' => ['required', 'string', 'max:255']])['reason'];
        $action->handle($receipt, $reason);

        return $request->expectsJson() ? response()->json(['saved' => true]) : back()->with('success', 'Pedido rejeitado sem cobrança.');
    }
}
