<?php

namespace App\Http\Controllers;

use App\Actions\ConfirmMenuOrder;
use App\Actions\RejectMenuOrder;
use App\Models\MenuOrderReceipt;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class MenuOrderReceiptController extends Controller
{
    /**
     * Pedidos do QR aguardando a equipe, para o aviso global em qualquer tela.
     * A mesa vem da comanda (acompanha transferências).
     */
    public function pending(): JsonResponse
    {
        $receipts = MenuOrderReceipt::query()
            ->where('status', MenuOrderReceipt::PENDING)
            ->with('order.diningTable:id,number')
            ->orderBy('id')
            ->limit(20)
            ->get()
            ->map(fn (MenuOrderReceipt $receipt): array => [
                'id' => $receipt->id,
                'table' => (string) $receipt->order?->diningTable?->number,
                'order_id' => $receipt->table_order_id,
                'units' => (int) $receipt->units,
                'total' => $receipt->total,
                'created_at' => $receipt->created_at?->toIso8601String(),
            ]);

        return response()->json(['receipts' => $receipts])->header('Cache-Control', 'no-store, private');
    }

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
