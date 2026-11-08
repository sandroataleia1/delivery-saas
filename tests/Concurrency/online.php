<?php

/** Cenários O1–O4: envio, aceite, expiração e esgotamento da loja online sob processos independentes. */
use App\Actions\PlaceOnlineOrder;
use App\Models\DeliveryOrder;
use App\Models\OnlineOrder;
use App\Models\OnlineStore;
use App\Models\OnlineStoreHour;
use App\Models\ProductionWork;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;

Auth::setUser($admin);
$store = OnlineStore::factory()->create(['company_id' => $company->id, 'enabled' => true, 'accept_minutes' => 15]);
// Turno 00:00–00:00 é de virada: a loja fica aberta o dia inteiro, todos os dias, no fuso de São Paulo.
foreach (range(0, 6) as $weekday) {
    (new OnlineStoreHour)->forceFill(['company_id' => $company->id, 'weekday' => $weekday, 'opens_at' => '00:00:00', 'closes_at' => '00:00:00'])->save();
}
$phoneSequence = 0;
// Telefone novo por pedido: o limite de pendentes por telefone não interfere nos cenários.
$onlinePayload = function (array $productIds, ?string $uuid = null) use (&$phoneSequence, $u): array {
    $phoneSequence++;

    return ['client_uuid' => $uuid ?? $u(), 'type' => 'pickup', 'customer' => ['name' => 'Cliente Concorrente', 'phone' => '1198'.str_pad((string) $phoneSequence, 7, '0', STR_PAD_LEFT)],
        'items' => array_map(fn (int $id) => ['product_id' => $id, 'quantity' => 1], $productIds), 'payment_method' => 'pix'];
};
$placeOnline = fn (array $productIds): OnlineOrder => Context::scope(fn () => app(PlaceOnlineOrder::class)->handle($store, $onlinePayload($productIds)), hidden: ['audit_actor' => PlaceOnlineOrder::GUEST])['order'];
$deliveriesOf = fn (OnlineOrder $order) => DeliveryOrder::where('online_order_id', $order->id)->count();
$worksOf = fn (OnlineOrder $order) => ProductionWork::whereIn('delivery_order_item_id', DB::table('delivery_order_items')->whereIn('delivery_order_id', DeliveryOrder::where('online_order_id', $order->id)->pluck('id'))->pluck('id'))->count();
$decisionsOf = fn (OnlineOrder $order) => DB::table('audit_logs')->where('auditable_type', 'OnlineOrder')->where('auditable_id', $order->id)->where('event', 'updated')->count();
$lockFailures = fn (array $results, int $code) => count(array_filter($results, fn ($result) => ! $result['ok'] && ($result['code'] ?? null) === $code));
$lockInvariants = fn (array $results) => [inv('deadlocks 1213', $lockFailures($results, 1213), 0), inv('timeouts 1205', $lockFailures($results, 1205), 0)];

// O1: oito envios simultâneos com o mesmo client_uuid e o mesmo conteúdo.
$pOnline = $product();
$sameUuid = $u();
$samePayload = $onlinePayload([$pOnline->id], $sameUuid);
$numberBefore = (int) OnlineOrder::where('company_id', $company->id)->max('number');
$ordersBefore = OnlineOrder::where('company_id', $company->id)->count();
$r = run_all(array_fill(0, 8, ['placeonline', ['store' => $store->id, 'data' => $samePayload]]));
$sent = OnlineOrder::where('client_uuid', $sameUuid)->sole();
report('O1 oito envios do mesmo client_uuid', $r, [
    inv('respostas aceitas', tally($r)['ok'], 8),
    inv('pedidos online', OnlineOrder::where('client_uuid', $sameUuid)->count(), 1),
    inv('pedidos novos na empresa', OnlineOrder::where('company_id', $company->id)->count() - $ordersBefore, 1),
    inv('número único e sequencial', $sent->number, $numberBefore + 1),
    inv('linhas de snapshot', $sent->items()->count(), 1),
    inv('auditoria única', DB::table('audit_logs')->where('auditable_type', 'OnlineOrder')->where('auditable_id', $sent->id)->where('event', 'created')->count(), 1),
    inv('auditado como cliente', DB::table('audit_logs')->where('auditable_type', 'OnlineOrder')->where('auditable_id', $sent->id)->where('event', 'created')->value('user_name'), PlaceOnlineOrder::GUEST),
    inv('mesmo link para todos', count(array_unique(array_column($r, 'token_hash'))), 1),
    inv('link do pedido gravado', $r[0]['token_hash'] ?? null, $sent->tracking_token_hash),
    inv('nada antes do aceite', $deliveriesOf($sent), 0),
    ...$lockInvariants($r),
]);

// O2: aceitar × expirar no instante do prazo, 30 rodadas. O aceite usa o relógio real (antes do prazo);
// a expiração roda com o relógio no prazo. Quem travar primeiro decide; o outro precisa respeitar.
$expiryResults = [];
$expiryBad = 0;
$acceptedInRace = 0;
$expiredInRace = 0;
foreach (range(1, 30) as $round) {
    $order = $placeOnline([$pOnline->id]);
    $deadline = $order->fresh()->expires_at->toIso8601String();
    // Rodadas sem atraso disputam livremente; as demais forçam cada ordem para exercitar os dois desfechos.
    [$acceptDelay, $expireDelay] = match ($round % 3) {
        0 => [0, 0],
        1 => [0, 150000],
        2 => [150000, 0],
    };
    $r = run_all([
        ['acceptonline', ['user' => $admin->id, 'order' => $order->id, 'delay_after_barrier_us' => $acceptDelay]],
        ['expireonline', ['order' => $order->id, 'company' => $company->id, 'now' => $deadline, 'delay_after_barrier_us' => $expireDelay]],
        ['acceptonline', ['user' => $admin->id, 'order' => $order->id, 'delay_after_barrier_us' => $acceptDelay]],
        ['expireonline', ['order' => $order->id, 'company' => $company->id, 'now' => $deadline, 'delay_after_barrier_us' => $expireDelay]],
    ]);
    array_push($expiryResults, ...$r);
    $order->refresh();
    $accepted = $order->status === OnlineOrder::ACCEPTED && $deliveriesOf($order) === 1 && $worksOf($order) === 1;
    $expired = $order->status === OnlineOrder::EXPIRED && $deliveriesOf($order) === 0 && $worksOf($order) === 0;
    $acceptedInRace += $accepted ? 1 : 0;
    $expiredInRace += $expired ? 1 : 0;
    $expiryBad += ($accepted xor $expired) && $decisionsOf($order) === 1 ? 0 : 1;
}
report('O2 aceitar x expirar no prazo, 30 rodadas', $expiryResults, [
    inv('inconsistências', $expiryBad, 0),
    inv('aceite exercitado', $acceptedInRace > 0, true),
    inv('expiração exercitada', $expiredInRace > 0, true),
    ...$lockInvariants($expiryResults),
]);
echo json_encode(['info' => 'O2 desfechos', 'aceitos' => $acceptedInRace, 'expirados' => $expiredInRace])."\n";

// O3: aceitar × esgotar o produto, nas duas ordens.
$soldOutResults = [];
$soldOutBad = 0;
$acceptedBeforeSoldOut = 0;
$refusedAfterSoldOut = 0;
foreach (range(1, 10) as $round) {
    $pSoldOut = $product();
    $order = $placeOnline([$pSoldOut->id]);
    $soldOutFirst = $round % 2 === 0;
    $r = run_all([
        ['acceptonline', ['user' => $admin->id, 'order' => $order->id, 'delay_after_barrier_us' => $soldOutFirst ? 150000 : 0]],
        ['soldout', ['user' => $admin->id, 'product' => $pSoldOut->id, 'delay_after_barrier_us' => $soldOutFirst ? 0 : 150000]],
    ]);
    array_push($soldOutResults, ...$r);
    $order->refresh();
    $accepted = $order->status === OnlineOrder::ACCEPTED;
    $acceptedBeforeSoldOut += $accepted ? 1 : 0;
    $refusedAfterSoldOut += $accepted ? 0 : 1;
    $valid = ($accepted || $order->status === OnlineOrder::PENDING)
        && $deliveriesOf($order) === ($accepted ? 1 : 0)
        && $worksOf($order) === ($accepted ? 1 : 0)
        && $pSoldOut->fresh()->sold_out;
    $soldOutBad += $valid ? 0 : 1;
}
report('O3 aceitar x esgotar, dez rodadas', $soldOutResults, [
    inv('inconsistências', $soldOutBad, 0),
    inv('aceitar primeiro exercitado', $acceptedBeforeSoldOut > 0, true),
    inv('esgotar primeiro exercitado', $refusedAfterSoldOut > 0, true),
    ...$lockInvariants($soldOutResults),
]);

// O4: oito aceites simultâneos do mesmo pedido com duas linhas.
$order = $placeOnline([$product()->id, $product()->id]);
$r = run_all(array_fill(0, 8, ['acceptonline', ['user' => $admin->id, 'order' => $order->id]]));
$order->refresh();
$delivery = DeliveryOrder::where('online_order_id', $order->id)->first();
report('O4 oito aceites do mesmo pedido', $r, [
    inv('respostas aceitas', tally($r)['ok'], 8),
    inv('status', $order->status, OnlineOrder::ACCEPTED),
    inv('deliveries', $deliveriesOf($order), 1),
    inv('linhas no delivery', $delivery?->items()->count(), 2),
    inv('produção por linha', $worksOf($order), 2),
    inv('decisão auditada uma vez', $decisionsOf($order), 1),
    ...$lockInvariants($r),
]);
