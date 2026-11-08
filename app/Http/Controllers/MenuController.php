<?php

namespace App\Http\Controllers;

use App\Actions\PlaceMenuOrder;
use App\Http\Requests\StoreMenuOrderRequest;
use App\Models\Category;
use App\Models\Company;
use App\Models\DiningTable;
use App\Models\MenuOrderReceipt;
use App\Models\Product;
use App\Support\RestaurantOperationPolicy;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

class MenuController extends Controller
{
    public function show(string $token, RestaurantOperationPolicy $policy): Response
    {
        $table = $this->table($token);

        return Inertia::render('menu/Show', [
            'token' => $token, 'company' => Company::findOrFail($table->company_id)->trade_name,
            'table' => $table->number, 'serviceContext' => $table->service_context, 'order' => null,
            'limits' => array_intersect_key($policy->resolve(Company::findOrFail($table->company_id)), array_flip(['max_lines', 'max_line_units', 'max_order_units', 'max_order_value'])),
            'categories' => Category::withoutGlobalScopes()->where('company_id', $table->company_id)->orderBy('name')->get(['id', 'name']),
            'products' => Product::withoutGlobalScopes()->where('company_id', $table->company_id)->where('active', true)->where('sellable', true)
                ->orderBy('name')->get(['id', 'name', 'description', 'sale_price', 'unit', 'sold_out', 'category_id']),
        ]);
    }

    public function store(StoreMenuOrderRequest $request, string $token, PlaceMenuOrder $action): HttpResponse
    {
        $table = $this->table($token);
        $receipt = Context::scope(fn () => $action->handle($table, $request->validated()), hidden: ['audit_actor' => PlaceMenuOrder::GUEST]);

        return $request->expectsJson()
            ? response()->json(['receipt' => $this->present($receipt)], $receipt->wasRecentlyCreated ? 201 : 200)
            : back()->with('success', 'Pedido recebido, aguardando confirmação.');
    }

    public function track(Request $request, string $token, string $uuid): HttpResponse
    {
        $table = $this->table($token);
        $receipt = MenuOrderReceipt::withoutGlobalScopes()->where('company_id', $table->company_id)
            ->where('dining_table_id', $table->id)->where('client_uuid', $uuid)->firstOrFail();
        $key = $request->header('X-Menu-Receipt-Key', '');
        abort_unless(is_string($key) && preg_match('/\A[a-f0-9]{64}\z/', $key)
            && hash_equals($receipt->tracking_key_hash, hash('sha256', $key)), 404);

        return response()->json(['receipt' => $this->present($receipt)])->header('Cache-Control', 'no-store, private');
    }

    private function present(MenuOrderReceipt $receipt): array
    {
        $receipt->loadMissing('items.orderItem.production', 'order');

        return ['uuid' => $receipt->client_uuid, 'status' => $receipt->status, 'total' => $receipt->total,
            'attendance_cancelled' => $receipt->order->status === 'cancelled',
            'message' => match ($receipt->status) {
                'pending' => 'Pedido recebido, aguardando confirmação.',
                'rejected' => 'Pedido rejeitado pela equipe, sem cobrança.',
                default => $receipt->order->status === 'cancelled' ? 'Atendimento cancelado.' : 'Confirmado e encaminhado à produção.',
            },
            'items' => $receipt->items->map(fn ($item) => [...$item->only('product_name', 'quantity', 'unit_price', 'total', 'notes'),
                'production' => $item->orderItem?->production?->only('status', 'version', 'delivered_at', 'cancelled_at'),
                'cancelled' => $item->orderItem?->cancelled_at !== null])->all()];
    }

    private function table(string $token): DiningTable
    {
        $table = DiningTable::withoutGlobalScopes()->where('qr_token', $token)->where('active', true)->firstOrFail();
        $company = Company::findOrFail($table->company_id);
        abort_unless($company->active && $company->qr_menu_enabled, 404);

        return $table;
    }
}
