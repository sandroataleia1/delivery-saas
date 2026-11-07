<?php

/** Cenários R1–R5: invariantes do recebimento e decisões sob processos independentes. */
use App\Actions\AddOrderItems;
use App\Actions\OpenTable;
use App\Models\DiningTable;
use App\Models\MenuOrderReceipt;
use App\Models\RestaurantOperationSettings;
use App\Models\Sale;
use App\Models\StockMovement;
use App\Models\TableOrder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

Auth::setUser($admin);
$pqr = $product();
$makeTable = fn (int $number) => DiningTable::factory()->create(['company_id' => $company->id, 'number' => $number]);
$job = fn ($table, ?string $uuid = null) => ['qr', ['table' => $table->id, 'context' => $table->service_context, 'product' => $pqr->id, 'uuid' => $uuid ?? $u()]];
$table = $makeTable(300);
$uuid = $u();
$r = run_all(array_fill(0, 8, $job($table, $uuid)));
$receipt = MenuOrderReceipt::where('client_uuid', $uuid)->sole();
report('R1 oito reenvios da mesma chave', $r, [
    inv('respostas aceitas', tally($r)['ok'], 8), inv('recebimentos', MenuOrderReceipt::where('client_uuid', $uuid)->count(), 1),
    inv('linhas de snapshot', $receipt->items()->count(), 1), inv('subtotal', $receipt->order->subtotal(), '0.00'),
    inv('auditoria única', DB::table('audit_logs')->where('auditable_type', 'MenuOrderReceipt')->where('auditable_id', $receipt->id)->where('event', 'created')->count(), 1),
]);
$r = run_all([
    ['confirmreceipt', ['user' => $admin->id, 'receipt' => $receipt->id]],
    ['rejectreceipt', ['user' => $admin->id, 'receipt' => $receipt->id]],
    ['confirmreceipt', ['user' => $admin->id, 'receipt' => $receipt->id]],
    ['rejectreceipt', ['user' => $admin->id, 'receipt' => $receipt->id]],
]);
$receipt->refresh();
report('R2 confirmar x rejeitar, decisão única', $r, [
    inv('estado final', in_array($receipt->status, ['confirmed', 'rejected'], true), true),
    inv('itens somente quando confirmado', $receipt->order->items()->count(), $receipt->status === 'confirmed' ? 1 : 0),
    inv('subtotal coerente', $receipt->order->subtotal(), $receipt->status === 'confirmed' ? '10.00' : '0.00'),
    inv('decisão auditada uma vez', DB::table('audit_logs')->where('auditable_type', 'MenuOrderReceipt')->where('auditable_id', $receipt->id)->where('event', 'updated')->count(), 1),
    inv('saldo inalterado', $pqr->fresh()->stock_quantity, '10.000'),
]);
RestaurantOperationSettings::where('company_id', $company->id)->update(['max_pending_orders' => 1]);
$table = $makeTable(301);
$r = run_all(array_map(fn () => $job($table), range(1, 8)));
report('R3 limite acumulado não permite fracionamento concorrente', $r, [
    inv('aceitos', tally($r)['ok'], 1), inv('recebimentos', MenuOrderReceipt::where('dining_table_id', $table->id)->count(), 1),
    inv('comanda única', TableOrder::where('dining_table_id', $table->id)->count(), 1),
]);
$table = $makeTable(302);
$order = app(OpenTable::class)->handle($table, 1, $admin);
app(AddOrderItems::class)->handle($order, [['product_id' => $pqr->id, 'quantity' => 1]], $u());
$beforeStock = $pqr->fresh()->stock_quantity;
$beforeMovements = StockMovement::where('product_id', $pqr->id)->count();
$saleUuid = $u();
$r = run_all([$job($table), ['close', ['user' => $admin->id, 'order' => $order->id, 'uuid' => $saleUuid, 'subtotal' => '10.00']]]);
$order->refresh();
$pending = MenuOrderReceipt::where('table_order_id', $order->id)->count();
$sales = Sale::where('client_uuid', $saleUuid)->count();
report('R4 receber x fechar não deixa pedido órfão nem cobrado sem confirmação', $r, [
    inv('um vencedor', tally($r)['ok'], 1), inv('pendente ou venda', $pending + $sales, 1),
    inv('estado coerente', $order->status, $pending ? 'open' : 'closed'),
    inv('estoque coerente', $pqr->fresh()->stock_quantity, $sales ? bcsub($beforeStock, '1', 3) : $beforeStock),
    inv('movimentos coerentes', StockMovement::where('product_id', $pqr->id)->count() - $beforeMovements, $sales),
    inv('itens sem acréscimo de pendente', $order->items()->count(), 1),
]);
$from = $makeTable(303);
$to = $makeTable(304);
$order = app(OpenTable::class)->handle($from, 1, $admin);
$r = run_all([$job($from), ['transfer', ['user' => $admin->id, 'order' => $order->id, 'target' => $to->id]]]);
$order->refresh();
report('R5 receber x transferir mantém atendimento e invalida contexto antigo', $r, [
    inv('destino da comanda', $order->open_table_id, $to->id),
    inv('nenhuma nova comanda na origem', TableOrder::where('open_table_id', $from->id)->count(), 0),
    inv('recebimentos vinculados à mesma comanda', MenuOrderReceipt::where('dining_table_id', $from->id)->where('table_order_id', '!=', $order->id)->count(), 0),
    inv('origem invalidada', $from->fresh()->service_context !== $from->service_context, true),
    inv('sem itens cobrados', $order->items()->count(), 0),
]);
