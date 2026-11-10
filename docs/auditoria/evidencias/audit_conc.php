<?php

/** Harness de concorrência da auditoria. Só roda contra o MariaDB descartável (porta 33977). */

use App\Actions\CancelSale;
use App\Actions\CloseCashSession;
use App\Actions\CloseTableOrder;
use App\Actions\OpenCashSession;
use App\Actions\OpenTable;
use App\Actions\AddOrderItems;
use App\Actions\PlaceMenuOrder;
use App\Actions\RegisterSale;
use App\Actions\SettleBill;
use App\Actions\TransferTable;
use App\Models\Bill;
use App\Models\CashMovement;
use App\Models\CashSession;
use App\Models\Company;
use App\Models\DiningTable;
use App\Models\Product;
use App\Models\Role;
use App\Models\Sale;
use App\Models\SalePayment;
use App\Models\StockMovement;
use App\Models\TableOrder;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

require __DIR__.'/vendor/autoload.php';
$app = require __DIR__.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

if ((int) config('database.connections.mariadb.port') !== 33977 || config('database.default') !== 'mariadb') {
    exit("RECUSADO: não é o banco descartável\n");
}

function sale_payload(int $productId, string $uuid, string $price = '10.00', string $method = 'cash'): array
{
    return ['client_uuid' => $uuid, 'items' => [['product_id' => $productId, 'quantity' => '1', 'expected_price' => $price]], 'payments' => [['method' => $method, 'amount' => $price]]];
}

// ---- WORKER ----
if (($argv[1] ?? '') === 'worker') {
    [$_, $_w, $op, $startAt, $json] = $argv;
    $p = json_decode(base64_decode($json), true);
    if (isset($p['user'])) {
        Auth::setUser(User::find($p['user']));
    }
    while (microtime(true) < (float) $startAt) {
        usleep(200);
    }
    $t = microtime(true);
    try {
        match ($op) {
            'sale' => app(RegisterSale::class)->handle(sale_payload($p['product'], $p['uuid'], $p['price'] ?? '10.00')),
            'qr' => app(PlaceMenuOrder::class)->handle(DiningTable::withoutGlobalScopes()->find($p['table']), [['product_id' => $p['product'], 'quantity' => 1, 'notes' => null]], $p['uuid'], null),
            'close' => app(CloseTableOrder::class)->handle(TableOrder::find($p['order']), ['client_uuid' => $p['uuid'], 'expected_subtotal' => $p['subtotal'], 'charge_service' => false, 'payments' => [['method' => 'pix', 'amount' => $p['subtotal']]]]),
            'transfer' => app(TransferTable::class)->handle(TableOrder::find($p['order']), DiningTable::find($p['target'])),
            'settle' => app(SettleBill::class)->handle(Bill::find($p['bill']), ['paid_at' => today()->format('Y-m-d'), 'paid_amount' => '100.00', 'payment_method' => 'cash']),
            'closecash' => app(CloseCashSession::class)->handle(CashSession::find($p['session']), '0', 'auditoria'),
            'opencash' => app(OpenCashSession::class)->handle(User::find($p['user']), '0'),
            'cancel' => app(CancelSale::class)->handle(Sale::find($p['sale']), 'auditoria'),
        };
        echo json_encode(['ok' => true, 'ms' => round((microtime(true) - $t) * 1000)]);
    } catch (Throwable $e) {
        echo json_encode(['ok' => false, 'ms' => round((microtime(true) - $t) * 1000), 'class' => class_basename($e), 'msg' => Str::limit(str_replace(["\n", "\r"], ' ', $e->getMessage()), 110)]);
    }
    exit;
}

// ---- ORQUESTRADOR ----
function run_all(array $jobs, float $delay = 9.0): array
{
    $startAt = microtime(true) + $delay;
    $procs = [];
    foreach ($jobs as [$op, $params]) {
        $cmd = 'php '.escapeshellarg(__FILE__).' worker '.$op.' '.$startAt.' '.base64_encode(json_encode($params));
        $pipes = [];
        $proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, __DIR__);
        $procs[] = [$proc, $pipes, $op];
    }
    $out = [];
    foreach ($procs as [$proc, $pipes, $op]) {
        $raw = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($proc);
        $out[] = ['op' => $op] + (json_decode($raw, true) ?? ['ok' => false, 'class' => 'CRASH', 'msg' => substr($raw, 0, 110)]);
    }

    return $out;
}

function tally(array $results): array
{
    $ok = count(array_filter($results, fn ($r) => $r['ok']));
    $errors = [];
    foreach ($results as $r) {
        if (! $r['ok']) {
            $k = $r['class'].': '.$r['msg'];
            $errors[$k] = ($errors[$k] ?? 0) + 1;
        }
    }
    $times = array_column($results, 'ms');
    sort($times);

    return ['workers' => count($results), 'ok' => $ok, 'errors' => $errors, 'ms_max' => end($times)];
}

function report(string $name, array $results, array $invariants): void
{
    $pass = ! in_array(false, array_column($invariants, 'pass'), true);
    echo json_encode(['cenario' => $name, 'resultado' => $pass ? 'PASSOU' : 'FALHOU', 'tally' => tally($results), 'invariantes' => $invariants], JSON_UNESCAPED_UNICODE)."\n";
}

function inv(string $what, $got, $want): array
{
    return ['pass' => (string) $got === (string) $want, 'what' => $what, 'got' => (string) $got, 'want' => (string) $want];
}

$company = Company::factory()->create();
$role = Role::factory()->admin()->create(['company_id' => $company->id]);
$admin = User::factory()->forRole($role)->create();
Auth::setUser($admin);
$product = fn (array $a = []) => Product::factory()->create(['company_id' => $company->id, 'sale_price' => '10', 'cost_price' => '4', 'stock_quantity' => '10', 'track_stock' => true, ...$a]);
$cash = app(OpenCashSession::class)->handle($admin, '0');
$u = fn () => (string) Str::uuid();

// C1: duplo envio simultâneo da mesma venda.
$p1 = $product();
$uuid = $u();
$r = run_all(array_fill(0, 12, ['sale', ['user' => $admin->id, 'product' => $p1->id, 'uuid' => $uuid]]));
report('C1 mesma venda 12x (mesmo client_uuid)', $r, [
    inv('vendas', Sale::where('client_uuid', $uuid)->count(), 1),
    inv('saldo', $p1->fresh()->stock_quantity, '9.000'),
]);

// C2: última unidade / saldo consistente sob disputa.
$p2 = $product(['stock_quantity' => '5']);
$r = run_all(array_map(fn () => ['sale', ['user' => $admin->id, 'product' => $p2->id, 'uuid' => (string) Str::uuid()]], range(1, 20)));
$sold = Sale::query()->whereIn('id', DB::table('sale_items')->where('product_id', $p2->id)->pluck('sale_id'))->count();
$numbers = Sale::pluck('number');
report('C2 20 vendas simultâneas, saldo 5', $r, [
    inv('saldo = 5 - vendidas', (float) $p2->fresh()->stock_quantity, 5 - $sold),
    inv('soma movimentos = -vendidas', (float) StockMovement::where('product_id', $p2->id)->sum('quantity'), -$sold),
    inv('numeros unicos', $numbers->unique()->count(), $numbers->count()),
    inv('sequencia sem furo', $numbers->max() - $numbers->min() + 1, $numbers->count()),
]);
echo json_encode(['info' => 'C2 vendidas', 'vendidas' => $sold, 'saldo_final' => $p2->fresh()->stock_quantity])."\n";

// C3: QR simultâneo em mesa livre.
$p3 = $product();
$table = DiningTable::factory()->create(['company_id' => $company->id, 'number' => 101]);
$r = run_all(array_map(fn () => ['qr', ['table' => $table->id, 'product' => $p3->id, 'uuid' => (string) Str::uuid()]], range(1, 20)));
$orders = TableOrder::where('dining_table_id', $table->id)->get();
report('C3 20 pedidos QR simultâneos em mesa livre', $r, [
    inv('comandas abertas', $orders->count(), 1),
    inv('itens = pedidos ok', $orders->first()?->items()->count() ?? 0, tally($r)['ok']),
]);

// C4: fechamento simultâneo com client_uuid diferente (dois caixas/abas).
$table4 = DiningTable::factory()->create(['company_id' => $company->id, 'number' => 102]);
$o4 = app(OpenTable::class)->handle($table4, 1, $admin);
app(AddOrderItems::class)->handle($o4, [['product_id' => $p3->id, 'quantity' => 1, 'notes' => null]], $u());
$before = Sale::count();
$r = run_all(array_map(fn () => ['close', ['user' => $admin->id, 'order' => $o4->id, 'uuid' => (string) Str::uuid(), 'subtotal' => '10.00']], range(1, 6)));
report('C4 fechar mesma comanda 6x (uuids distintos)', $r, [
    inv('vendas novas', Sale::count() - $before, 1),
    inv('status', $o4->fresh()->status, 'closed'),
]);

// C5: baixa simultânea da mesma conta em dinheiro.
Auth::setUser($admin);
app(RegisterSale::class)->handle(sale_payload($product(['sale_price' => '500'])->id, $u(), '500.00'));
$bill = Bill::factory()->create(['company_id' => $company->id, 'type' => 'payable', 'amount' => '100', 'status' => 'open']);
$before = CashMovement::count();
$r = run_all(array_fill(0, 6, ['settle', ['user' => $admin->id, 'bill' => $bill->id]]));
report('C5 baixa em dinheiro 6x', $r, [
    inv('movimentos novos', CashMovement::count() - $before, 1),
    inv('status', $bill->fresh()->status, 'paid'),
]);

// C6: cancelamento simultâneo da mesma venda em dinheiro.
$p6 = $product();
$s6 = app(RegisterSale::class)->handle(sale_payload($p6->id, $u()))[0];
$before = CashMovement::count();
$r = run_all(array_fill(0, 6, ['cancel', ['user' => $admin->id, 'sale' => $s6->id]]));
report('C6 cancelar mesma venda 6x', $r, [
    inv('estornos', CashMovement::count() - $before, 1),
    inv('saldo restaurado', $p6->fresh()->stock_quantity, '10.000'),
]);

// C7: abrir o caixa 8x para o mesmo operador.
$userC7 = User::factory()->forRole($role)->create();
$r = run_all(array_fill(0, 8, ['opencash', ['user' => $userC7->id]]));
report('C7 abrir caixa 8x', $r, [inv('sessoes abertas', CashSession::where('open_user_id', $userC7->id)->count(), 1)]);

// C8: fechamento de caixa com vendas entrando.
$p8 = $product(['stock_quantity' => '100']);
$jobs = array_map(fn () => ['sale', ['user' => $userC7->id, 'product' => $p8->id, 'uuid' => (string) Str::uuid()]], range(1, 15));
$session = CashSession::where('open_user_id', $userC7->id)->sole();
array_splice($jobs, 7, 0, [['closecash', ['user' => $userC7->id, 'session' => $session->id]]]);
$r = run_all($jobs);
$session->refresh();
$cashOfSession = (float) SalePayment::whereIn('sale_id', Sale::where('cash_session_id', $session->id)->pluck('id'))->where('method', 'cash')->sum('amount');
report('C8 fechar caixa com 15 vendas simultâneas', $r, [
    inv('status', $session->status, 'closed'),
    inv('esperado congelado = vendas gravadas no caixa', (float) $session->expected_cash, $cashOfSession),
]);

// C9: fechar x transferir a mesma comanda (ordem de lock invertida), 30 rodadas.
$deadlocks = 0;
$lockWait = 0;
$all = [];
$bad = 0;
foreach (range(1, 30) as $round) {
    Auth::setUser($admin);
    $from = DiningTable::factory()->create(['company_id' => $company->id, 'number' => 1000 + $round * 2]);
    $to = DiningTable::factory()->create(['company_id' => $company->id, 'number' => 1001 + $round * 2]);
    $o = app(OpenTable::class)->handle($from, 1, $admin);
    app(AddOrderItems::class)->handle($o, [['product_id' => $p8->id, 'quantity' => 1, 'notes' => null]], $u());
    $startAt = 5.0;
    $r = run_all([
        ['close', ['user' => $admin->id, 'order' => $o->id, 'uuid' => $u(), 'subtotal' => '10.00']],
        ['transfer', ['user' => $admin->id, 'order' => $o->id, 'target' => $to->id]],
        ['close', ['user' => $admin->id, 'order' => $o->id, 'uuid' => $u(), 'subtotal' => '10.00']],
        ['transfer', ['user' => $admin->id, 'order' => $o->id, 'target' => $to->id]],
    ], $startAt);
    foreach ($r as $x) {
        $all[] = $x;
        if (! $x['ok'] && str_contains($x['msg'], '1213')) {
            $deadlocks++;
        }
        if (! $x['ok'] && str_contains($x['msg'], '1205')) {
            $lockWait++;
        }
    }
    if (! in_array($o->fresh()->status, ['closed', 'open'], true)) {
        $bad++;
    }
}
echo json_encode(['cenario' => 'C9 fechar x transferir, 30 rodadas, 4 workers', 'deadlocks_1213' => $deadlocks, 'lock_wait_1205' => $lockWait, 'tally' => tally($all)], JSON_UNESCAPED_UNICODE)."\n";
