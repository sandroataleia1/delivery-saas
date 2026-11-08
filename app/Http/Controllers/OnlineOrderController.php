<?php

namespace App\Http\Controllers;

use App\Actions\AcceptOnlineOrder;
use App\Actions\RejectOnlineOrder;
use App\Models\OnlineOrder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class OnlineOrderController extends Controller
{
    public function accept(OnlineOrder $onlineOrder, AcceptOnlineOrder $acceptOrder): RedirectResponse
    {
        $delivery = $acceptOrder->handle($onlineOrder);

        return back()->with('success', "Pedido online #{$onlineOrder->number} aceito como entrega #{$delivery->number}.");
    }

    public function reject(Request $request, OnlineOrder $onlineOrder, RejectOnlineOrder $rejectOrder): RedirectResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:255']], [], ['reason' => 'motivo']);

        $rejectOrder->handle($onlineOrder, $data['reason']);

        return back()->with('success', "Pedido online #{$onlineOrder->number} recusado.");
    }
}
