<?php

namespace App\Http\Controllers;

use App\Actions\PlaceMenuOrder;
use App\Models\Category;
use App\Models\Company;
use App\Models\DiningTable;
use App\Models\Product;
use App\Models\TableOrder;
use App\Models\TableOrderItem;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Cardápio público da mesa, sem login. Aqui nada pode depender do usuário da
 * sessão: um funcionário de outra empresa pode escanear o código. Toda consulta
 * filtra a empresa pela mesa, e só saem nome, descrição e preço dos produtos.
 */
class MenuController extends Controller
{
    public function show(string $token): Response
    {
        $table = $this->table($token);
        $company = Company::findOrFail($table->company_id);
        $order = $this->openOrder($table);

        return Inertia::render('menu/Show', [
            'token' => $token,
            'company' => $company->trade_name,
            'table' => $table->number,
            'categories' => Category::withoutGlobalScopes()->where('company_id', $table->company_id)->orderBy('name')->get(['id', 'name']),
            'products' => Product::withoutGlobalScopes()
                ->where('company_id', $table->company_id)
                ->where('active', true)
                ->where('sellable', true)
                ->orderBy('name')
                ->get(['id', 'name', 'description', 'sale_price', 'unit', 'category_id', 'barcode', 'sku']),
            'order' => $order ? [
                'id' => $order->id,
                'subtotal' => bcadd((string) TableOrderItem::withoutGlobalScopes()->where('table_order_id', $order->id)->whereNull('cancelled_at')->sum('total'), '0', 2),
                'items' => TableOrderItem::withoutGlobalScopes()
                    ->where('table_order_id', $order->id)
                    ->whereNull('cancelled_at')
                    ->orderBy('id')
                    ->get()
                    ->map(fn (TableOrderItem $item) => $item->only('id', 'product_name', 'quantity', 'total', 'notes')),
            ] : null,
        ]);
    }

    public function store(Request $request, string $token, PlaceMenuOrder $placeOrder): RedirectResponse
    {
        $table = $this->table($token);

        $data = $request->validate([
            'client_uuid' => ['required', 'uuid'],
            'known_order_id' => ['nullable', 'integer'],
            'items' => ['required', 'array', 'min:1', 'max:30'],
            'items.*.product_id' => [
                'bail',
                'required',
                'integer',
                Rule::exists('products', 'id')->where('company_id', $table->company_id)->where('active', true)->where('sellable', true),
            ],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:50'],
            'items.*.notes' => ['nullable', 'string', 'max:120'],
        ]);

        Context::addHidden('audit_actor', PlaceMenuOrder::GUEST);

        $placeOrder->handle($table, $data['items'], $data['client_uuid'], $data['known_order_id'] ?? null);

        return back()->with('success', 'Pedido enviado! Já vai para a cozinha.');
    }

    private function table(string $token): DiningTable
    {
        $table = DiningTable::withoutGlobalScopes()->where('qr_token', $token)->where('active', true)->firstOrFail();
        $company = Company::findOrFail($table->company_id);

        abort_unless($company->active && $company->qr_menu_enabled, 404);

        return $table;
    }

    private function openOrder(DiningTable $table): ?TableOrder
    {
        return TableOrder::withoutGlobalScopes()->where('open_table_id', $table->id)->first();
    }
}
