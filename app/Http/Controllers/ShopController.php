<?php

namespace App\Http\Controllers;

use App\Actions\PlaceOnlineOrder;
use App\Http\Requests\StoreOnlineOrderRequest;
use App\Models\Category;
use App\Models\Company;
use App\Models\DeliveryZone;
use App\Models\OnlineOrder;
use App\Models\OnlineStore;
use App\Models\Product;
use App\Models\WhatsAppSetting;
use App\Support\OnlineOrderProgress;
use App\Support\ProductComposition;
use App\Support\StoreSchedule;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/** Loja online pública: sem login, sempre filtrando pela empresa dona do slug. */
class ShopController extends Controller
{
    /** Cardápio e finalização. Só monta arrays explícitos: nada de SKU, código de barras, custo ou ficha técnica. */
    public function show(string $slug): Response
    {
        $store = OnlineStore::withoutGlobalScopes()->where('slug', $slug)->where('enabled', true)->with('hours')->first();
        $company = $store ? Company::whereKey($store->company_id)->where('active', true)->first() : null;
        abort_unless($company, 404);

        $now = CarbonImmutable::now();
        $products = Product::withoutGlobalScopes()->where('company_id', $company->id)->where('active', true)->where('sellable', true)
            ->orderBy('name')->get(['id', 'name', 'description', 'sale_price', 'sold_out', 'category_id', 'image_path']);
        $labels = config('sales.payment_methods');

        return Inertia::render('shop/Show', [
            'store' => [
                'name' => $company->trade_name,
                'logo_url' => $store->logoUrl(),
                'slug' => $store->slug,
                'open' => StoreSchedule::isOpen($store, $now),
                'label' => StoreSchedule::label($store, $now),
                'eta_text' => $store->eta_text,
                'min_order' => $store->min_order,
                'accepts_delivery' => $store->accepts_delivery,
                'accepts_pickup' => $store->accepts_pickup,
                'whatsapp' => $store->whatsapp,
            ],
            'categories' => Category::withoutGlobalScopes()->where('company_id', $company->id)->orderBy('name')->get(['id', 'name'])
                ->map(fn (Category $category) => ['id' => $category->id, 'name' => $category->name])->all(),
            'products' => $products->map(fn (Product $product) => [
                'id' => $product->id,
                'name' => $product->name,
                'description' => $product->description,
                'sale_price' => $product->sale_price,
                'sold_out' => (bool) $product->sold_out,
                'category_id' => $product->category_id,
                'image_url' => $product->imageUrl(),
            ])->all(),
            'composition' => ProductComposition::forProducts($products),
            'zones' => DeliveryZone::withoutGlobalScopes()->where('company_id', $company->id)->orderBy('name')->get(['id', 'name', 'fee'])
                ->map(fn (DeliveryZone $zone) => ['id' => $zone->id, 'name' => $zone->name, 'fee' => $zone->fee])->all(),
            'payment_methods' => collect(config('online.payment_methods'))->mapWithKeys(fn (string $method) => [$method => $labels[$method] ?? $method])->all(),
        ]);
    }

    public function store(StoreOnlineOrderRequest $request, string $slug, PlaceOnlineOrder $action): JsonResponse
    {
        $store = $request->onlineStore();
        ['order' => $order, 'token' => $token] = Context::scope(
            fn () => $action->handle($store, $request->validated()),
            hidden: ['audit_actor' => PlaceOnlineOrder::GUEST],
        );

        return response()->json([
            'number' => (int) $order->number,
            'tracking_url' => route('shop.track', [$store->slug, $token]),
        ], $order->wasRecentlyCreated ? 201 : 200)->header('Cache-Control', 'no-store, private');
    }

    /** Página de acompanhamento pelo link secreto. Segue valendo mesmo com a loja pausada ou desativada. */
    public function track(Request $request, string $slug, string $token): SymfonyResponse
    {
        $response = Inertia::render('shop/Track', [...$this->trackingPayload($slug, $token), 'slug' => $slug, 'token' => $token, 'whatsapp_updates' => $this->whatsappUpdatesFor($slug)])->toResponse($request);
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }

    /** Verdadeiro s� com WhatsApp ativado, com inst�ncia e conectado. Rota p�blica: consulta por company_id expl�cito. */
    private function whatsappUpdatesFor(string $slug): bool
    {
        $companyId = OnlineStore::withoutGlobalScopes()->where('slug', $slug)->value('company_id');

        $setting = $companyId ? WhatsAppSetting::withoutGlobalScopes()->where('company_id', $companyId)->first() : null;

        return (bool) ($setting && $setting->enabled && filled($setting->instance_name) && $setting->isConnected());
    }

    /** Estado atual para o polling da página. */
    public function status(string $slug, string $token): JsonResponse
    {
        return response()->json($this->trackingPayload($slug, $token))->header('Cache-Control', 'no-store, private');
    }

    /**
     * Pedido pelo slug e pelo hash do token: 404 para formato inválido, token desconhecido ou pedido de outra loja.
     *
     * @return array{store: array{name: string, whatsapp: ?string}, customer_first_name: string, progress: array<string, mixed>}
     */
    private function trackingPayload(string $slug, string $token): array
    {
        abort_unless(preg_match('/\A[A-Za-z0-9]{48}\z/', $token) === 1, 404);

        $store = OnlineStore::withoutGlobalScopes()->where('slug', $slug)->first();
        $hash = hash('sha256', $token);
        $order = $store ? OnlineOrder::withoutGlobalScopes()->where('company_id', $store->company_id)->where('tracking_token_hash', $hash)->first() : null;
        abort_unless($order && hash_equals((string) $order->tracking_token_hash, $hash), 404);

        $company = Company::whereKey($store->company_id)->first();

        return [
            'store' => ['name' => (string) ($company?->trade_name ?? ''), 'whatsapp' => $store->whatsapp],
            'customer_first_name' => Str::before(trim((string) $order->customer_name), ' '),
            'progress' => OnlineOrderProgress::for($order),
        ];
    }
}
