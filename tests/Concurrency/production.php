<?php

/** Production races use the same guarded instance and independent worker barrier. */
use App\Actions\AddOrderItems;
use App\Actions\OpenTable;
use App\Actions\PlaceMenuOrder;
use App\Actions\TransitionProduction;
use App\Models\DiningTable;
use App\Models\ProductionWork;
use App\Models\Sale;
use App\Models\StockMovement;
use App\Models\TableOrderItem;
use Illuminate\Support\Facades\Auth;

Auth::setUser($admin);
$pProd = $product();
$prodTable = DiningTable::factory()->create(['company_id' => $company->id, 'number' => 400]);
$prodOrder = app(OpenTable::class)->handle($prodTable, 1, $admin);
$dispatchUuid = $u();
$r = run_all(array_fill(0, 8, ['dispatchitems', ['user' => $admin->id, 'order' => $prodOrder->id, 'product' => $pProd->id, 'uuid' => $dispatchUuid]]));
$work = ProductionWork::whereIn('table_order_item_id', $prodOrder->items()->pluck('id'))->sole();
report('P1 oito lançamentos iguais encaminham uma linha', $r, [inv('aceitos', tally($r)['ok'], 8), inv('itens', $prodOrder->items()->count(), 1), inv('trabalho', ProductionWork::where('table_order_item_id', $work->table_order_item_id)->count(), 1), inv('eventos', $work->events()->count(), 1), inv('saldo', $pProd->fresh()->stock_quantity, '10.000')]);
$r = run_all(array_fill(0, 8, ['productionstage', ['user' => $admin->id, 'work' => $work->id, 'version' => 0, 'target' => 'preparing']]));
report('P2 oito transições com mesma versão', $r, [inv('aceita uma', tally($r)['ok'], 1), inv('estado', $work->fresh()->status, 'preparing'), inv('versão', $work->fresh()->version, 1), inv('eventos', $work->events()->count(), 2), inv('sem estoque', StockMovement::where('product_id', $pProd->id)->count(), 0)]);
$raceResults = [];
$badProduction = 0;
$closedProduction = 0;
$cancelledProduction = 0;
foreach (range(1, 6) as $round) {
    $pRace = $product();
    $tRace = DiningTable::factory()->create(['company_id' => $company->id, 'number' => 410 + $round]);
    $oRace = app(OpenTable::class)->handle($tRace, 1, $admin);
    app(AddOrderItems::class)->handle($oRace, [['product_id' => $pRace->id, 'quantity' => '1']], $u());
    $wRace = ProductionWork::whereIn('table_order_item_id', $oRace->items()->pluck('id'))->sole();
    app(TransitionProduction::class)->handle($wRace, 0, 'preparing');
    app(TransitionProduction::class)->handle($wRace, 1, 'ready');
    $cancelFirst = $round % 2 === 0;
    $saleUuid = $u();
    $r = run_all([
        ['productionstage', ['user' => $admin->id, 'work' => $wRace->id, 'version' => 2, 'target' => 'delivered', 'delay_after_barrier_us' => $cancelFirst ? 100000 : 0]],
        ['close', ['user' => $admin->id, 'order' => $oRace->id, 'uuid' => $saleUuid, 'subtotal' => '10.00', 'delay_after_barrier_us' => 150000]],
        ['cancelitem', ['user' => $admin->id, 'item' => $wRace->table_order_item_id, 'delay_after_barrier_us' => $cancelFirst ? 0 : 300000]],
    ]);
    array_push($raceResults, ...$r);
    $oRace->refresh();
    $wRace->refresh();
    $closed = ! $oRace->isOpen();
    $cancelled = $wRace->cancelled_at !== null;
    $closedProduction += $closed ? 1 : 0;
    $cancelledProduction += $cancelled ? 1 : 0;
    $valid = ($closed ? $oRace->sale_id !== null && $wRace->delivered_at && ! $cancelled : $oRace->sale_id === null)
        && Sale::where('client_uuid', $saleUuid)->count() === ($closed ? 1 : 0)
        && StockMovement::where('product_id', $pRace->id)->count() === ($closed ? 1 : 0)
        && $pRace->fresh()->stock_quantity === ($closed ? '9.000' : '10.000')
        && $wRace->events()->where('to_state', 'cancelled')->count() === ($cancelled ? 1 : 0);
    $badProduction += $valid ? 0 : 1;
}
report('P3 entregar x cancelar x fechar, seis rodadas', $raceResults, [inv('inconsistências', $badProduction, 0), inv('fechamento exercitado', $closedProduction > 0, true), inv('cancelamento exercitado', $cancelledProduction > 0, true)]);
$availabilityResults = [];
$availabilityBad = 0;
$acceptedBeforeSoldOut = 0;
$refusedAfterSoldOut = 0;
foreach (range(1, 6) as $round) {
    $pAvailable = $product();
    $tAvailable = DiningTable::factory()->create(['company_id' => $company->id, 'number' => 430 + $round]);
    $receipt = app(PlaceMenuOrder::class)->handle($tAvailable, ['service_context' => $tAvailable->service_context, 'client_uuid' => $u(), 'tracking_key' => str_repeat('a', 64), 'items' => [['product_id' => $pAvailable->id, 'quantity' => 1]]]);
    $firstSoldOut = $round % 2 === 0;
    $r = run_all([
        ['confirmreceipt', ['user' => $admin->id, 'receipt' => $receipt->id, 'delay_after_barrier_us' => $firstSoldOut ? 150000 : 0]],
        ['soldout', ['user' => $admin->id, 'product' => $pAvailable->id, 'delay_after_barrier_us' => $firstSoldOut ? 0 : 150000]],
    ]);
    array_push($availabilityResults, ...$r);
    $receipt->refresh();
    $confirmed = $receipt->status === 'confirmed';
    $acceptedBeforeSoldOut += $confirmed ? 1 : 0;
    $refusedAfterSoldOut += $confirmed ? 0 : 1;
    $valid = $receipt->order->items()->count() === ($confirmed ? 1 : 0)
        && ProductionWork::whereIn('table_order_item_id', $receipt->order->items()->pluck('id'))->count() === ($confirmed ? 1 : 0)
        && $pAvailable->fresh()->sold_out && $pAvailable->fresh()->stock_quantity === '10.000'
        && StockMovement::where('product_id', $pAvailable->id)->count() === 0;
    $availabilityBad += $valid ? 0 : 1;
}
report('P4 confirmar x esgotar, seis rodadas', $availabilityResults, [inv('inconsistências', $availabilityBad, 0), inv('confirmar primeiro exercitado', $acceptedBeforeSoldOut > 0, true), inv('esgotar primeiro exercitado', $refusedAfterSoldOut > 0, true)]);
$tLegacy = DiningTable::factory()->create(['company_id' => $company->id, 'number' => 450]);
$oLegacy = app(OpenTable::class)->handle($tLegacy, 1, $admin);
$legacyItem = new TableOrderItem;
$legacyItem->forceFill(['company_id' => $company->id, 'table_order_id' => $oLegacy->id, 'product_id' => $pProd->id, 'client_uuid' => $u(), 'product_name' => $pProd->name, 'unit' => 'UN', 'quantity' => '1', 'unit_price' => '10', 'total' => '10', 'user_name' => $admin->name])->save();
$legacyWork = new ProductionWork;
$legacyWork->forceFill(['company_id' => $company->id, 'table_order_item_id' => $legacyItem->id, 'legacy' => true, 'version' => 0, 'product_name' => $pProd->name, 'unit' => 'UN', 'quantity' => '1', 'origin_label' => 'Comanda '.$oLegacy->number])->save();
$r = run_all(array_fill(0, 8, ['reconcileproduction', ['user' => $admin->id, 'work' => $legacyWork->id]]));
report('P5 oito reconciliações do mesmo legado', $r, [inv('aceita uma', tally($r)['ok'], 1), inv('observação registrada', $legacyWork->fresh()->reconciled_at !== null, true), inv('entrega observada', $legacyWork->fresh()->delivered_at !== null, true), inv('evento único', $legacyWork->events()->count(), 1), inv('sem venda', $oLegacy->fresh()->sale_id, null)]);
