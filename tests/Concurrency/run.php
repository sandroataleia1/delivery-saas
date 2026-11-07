<?php

/** C1–C9: integridade sob concorrência, exclusivamente em instância descartável. */

use App\Actions\AddOrderItems;
use App\Actions\CancelSale;
use App\Actions\CancelStockEntry;
use App\Actions\CloseCashSession;
use App\Actions\CloseTableOrder;
use App\Actions\ConfirmMenuOrder;
use App\Actions\OpenCashSession;
use App\Actions\OpenTable;
use App\Actions\PlaceMenuOrder;
use App\Actions\RegisterSale;
use App\Actions\RejectMenuOrder;
use App\Actions\ReopenBill;
use App\Actions\SettleBill;
use App\Actions\TransferTable;
use App\Models\Bill;
use App\Models\CashMovement;
use App\Models\CashSession;
use App\Models\Company;
use App\Models\DiningTable;
use App\Models\MenuOrderReceipt;
use App\Models\Product;
use App\Models\RestaurantOperationSettings;
use App\Models\Role;
use App\Models\Sale;
use App\Models\SalePayment;
use App\Models\StockEntry;
use App\Models\StockMovement;
use App\Models\TableOrder;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\Support\ConcurrencyBarrier;
use Tests\Support\ConcurrencyFailure;
use Tests\Support\DisposableDatabase;
use Tests\Support\TableCloseIntegrity;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

DisposableDatabase::guardApplication($app);
if (! in_array(config('database.default'), ['mariadb', 'mysql'], true)) {
    throw new RuntimeException('Concorrência exige MariaDB/MySQL descartável.');
}

function sale_payload(int $productId, string $uuid, string $price = '10.00', string $method = 'cash'): array
{
    return ['client_uuid' => $uuid, 'items' => [['product_id' => $productId, 'quantity' => '1', 'expected_price' => $price]], 'payments' => [['method' => $method, 'amount' => $price]]];
}

// ---- WORKER ----
if (($argv[1] ?? '') === 'worker') {
    [$_, $_w, $op, $startAt, $json] = $argv;
    $p = json_decode(base64_decode($json), true);
    if ($p['force_lock_overlap'] ?? false) {
        DB::listen(function ($query) use ($op) {
            $lock = str_contains(strtolower($query->sql), 'for update');
            $table = $op === 'close' ? '`table_orders`' : '`companies`';
            if ($lock && str_contains($query->sql, $table)) {
                usleep(250000);
            }
        });
    }
    if (isset($p['user'])) {
        Auth::setUser(User::find($p['user']));
    }
    ConcurrencyBarrier::awaitRelease($p['_barrier_directory'], $p['_worker_id']);
    if (isset($p['delay_after_barrier_us'])) {
        usleep($p['delay_after_barrier_us']);
    }
    $t = microtime(true);
    try {
        match ($op) {
            'sale' => app(RegisterSale::class)->handle(sale_payload($p['product'], $p['uuid'], $p['price'] ?? '10.00')),
            'qr' => app(PlaceMenuOrder::class)->handle(DiningTable::withoutGlobalScopes()->findOrFail($p['table']), ['service_context' => $p['context'], 'client_uuid' => $p['uuid'], 'tracking_key' => $p['key'] ?? str_repeat('a', 64), 'items' => [['product_id' => $p['product'], 'quantity' => 1, 'notes' => null]]]),
            'confirmreceipt' => app(ConfirmMenuOrder::class)->handle(MenuOrderReceipt::findOrFail($p['receipt'])),
            'rejectreceipt' => app(RejectMenuOrder::class)->handle(MenuOrderReceipt::findOrFail($p['receipt']), 'Rejeição concorrente'),
            'close' => app(CloseTableOrder::class)->handle(TableOrder::find($p['order']), ['client_uuid' => $p['uuid'], 'expected_subtotal' => $p['subtotal'], 'charge_service' => false, 'payments' => [['method' => 'pix', 'amount' => $p['subtotal']]]]),
            'transfer' => app(TransferTable::class)->handle(TableOrder::find($p['order']), DiningTable::find($p['target'])),
            'settle' => app(SettleBill::class)->handle(Bill::find($p['bill']), ['paid_at' => today()->format('Y-m-d'), 'paid_amount' => $p['amount'] ?? '100.00', 'payment_method' => $p['method'] ?? 'cash', 'discount_amount' => $p['discount'] ?? '0', 'discount_reason' => $p['discount_reason'] ?? null]),
            'reopenbill' => app(ReopenBill::class)->handle(Bill::find($p['bill']), 'Concorrência de estorno', $p['settlement']),
            'cancelentry' => app(CancelStockEntry::class)->handle(StockEntry::find($p['entry']), 'Concorrência de origem'),
            'closecash' => app(CloseCashSession::class)->handle(CashSession::find($p['session']), '0', 'auditoria'),
            'opencash' => app(OpenCashSession::class)->handle(User::find($p['user']), '0'),
            'cancel' => app(CancelSale::class)->handle(Sale::find($p['sale']), 'auditoria'),
        };
        echo json_encode(['ok' => true, 'started_at' => $t, 'ms' => round((microtime(true) - $t) * 1000)]);
    } catch (Throwable $e) {
        echo json_encode(['ok' => false, 'ms' => round((microtime(true) - $t) * 1000), 'class' => class_basename($e), 'code' => ConcurrencyFailure::driverCode($e), 'msg' => Str::limit(str_replace(["\n", "\r"], ' ', $e->getMessage()), 110)]);
    }
    exit;
}

// ---- ORQUESTRADOR ----
function run_all(array $jobs): array
{
    $barrier = dirname(getenv('DISPOSABLE_DB_MARKER')).'/barrier-'.bin2hex(random_bytes(8));
    mkdir($barrier, 0700);
    $procs = [];
    try {
        foreach ($jobs as $index => [$op, $params]) {
            $params += ['_barrier_directory' => $barrier, '_worker_id' => (string) $index];
            $process = new Process([PHP_BINARY, __FILE__, 'worker', $op, '0', base64_encode(json_encode($params))], dirname(__DIR__, 2), timeout: 45);
            $process->start();
            $procs[] = [$process, $op];
        }
        $deadline = microtime(true) + 20;
        while (count(glob($barrier.'/ready-*')) !== count($jobs)) {
            foreach ($procs as [$process]) {
                if (! $process->isRunning()) {
                    throw new RuntimeException('Worker saiu antes da barreira: '.$process->getErrorOutput());
                }
            }
            if (microtime(true) >= $deadline) {
                throw new RuntimeException('Timeout aguardando todos os workers na barreira.');
            }
            usleep(10000);
        }
        file_put_contents($barrier.'/release', 'go');
        $out = [];
        foreach ($procs as [$process, $op]) {
            $process->wait();
            $raw = $process->getOutput();
            $out[] = ['op' => $op, 'barrier_ready' => true] + (json_decode($raw, true) ?? ['ok' => false, 'class' => 'CRASH', 'msg' => substr($process->getErrorOutput().$raw, 0, 110)]);
        }

        return $out;
    } finally {
        foreach ($procs as [$process]) {
            $process->stop();
        }
        foreach (glob($barrier.'/*') as $file) {
            unlink($file);
        }
        rmdir($barrier);
    }
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

    return ['workers' => count($results), 'barrier_ready' => count(array_filter($results, fn ($result) => $result['barrier_ready'])), 'ok' => $ok, 'errors' => $errors, 'ms_max' => end($times)];
}

function report(string $name, array $results, array $invariants): void
{
    global $failedScenarios;
    $unexpected = array_filter($results, fn ($result) => ! $result['ok'] && ($result['class'] ?? '') !== 'ValidationException');
    $invariants[] = inv('erros inesperados', count($unexpected), 0);
    $pass = ! in_array(false, array_column($invariants, 'pass'), true);
    $failedScenarios += $pass ? 0 : 1;
    echo json_encode(['cenario' => $name, 'resultado' => $pass ? 'PASSOU' : 'FALHOU', 'tally' => tally($results), 'invariantes' => $invariants], JSON_UNESCAPED_UNICODE)."\n";
}

function inv(string $what, $got, $want): array
{
    return ['pass' => (string) $got === (string) $want, 'what' => $what, 'got' => (string) $got, 'want' => (string) $want];
}

Artisan::call('migrate:fresh', ['--force' => true]);
$failedScenarios = 0;
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
    inv('reenvios processados com sucesso', tally($r)['ok'], 12),
    inv('vendas', Sale::where('client_uuid', $uuid)->count(), 1),
    inv('saldo', $p1->fresh()->stock_quantity, '9.000'),
]);

// C2: última unidade / saldo consistente sob disputa.
$p2 = $product(['stock_quantity' => '5']);
$r = run_all(array_map(fn () => ['sale', ['user' => $admin->id, 'product' => $p2->id, 'uuid' => (string) Str::uuid()]], range(1, 20)));
$sold = Sale::query()->whereIn('id', DB::table('sale_items')->where('product_id', $p2->id)->pluck('sale_id'))->count();
$numbers = Sale::pluck('number');
report('C2 20 vendas simultâneas, saldo 5', $r, [
    inv('vendas aceitas', tally($r)['ok'], 20),
    inv('saldo = 5 - vendidas', (float) $p2->fresh()->stock_quantity, 5 - $sold),
    inv('soma movimentos = -vendidas', (float) StockMovement::where('product_id', $p2->id)->sum('quantity'), -$sold),
    inv('numeros unicos', $numbers->unique()->count(), $numbers->count()),
    inv('sequencia sem furo', $numbers->max() - $numbers->min() + 1, $numbers->count()),
]);
echo json_encode(['info' => 'C2 vendidas', 'vendidas' => $sold, 'saldo_final' => $p2->fresh()->stock_quantity])."\n";

// C3: QR simultâneo em mesa livre.
$p3 = $product();
$table = DiningTable::factory()->create(['company_id' => $company->id, 'number' => 101]);
RestaurantOperationSettings::factory()->create(['company_id' => $company->id, 'max_pending_orders' => 20]);
$r = run_all(array_map(fn () => ['qr', ['context' => $table->service_context, 'table' => $table->id, 'product' => $p3->id, 'uuid' => (string) Str::uuid()]], range(1, 20)));
$orders = TableOrder::where('dining_table_id', $table->id)->get();
report('C3 20 pedidos QR simultâneos em mesa livre', $r, [
    inv('pedidos aceitos', tally($r)['ok'], 20),
    inv('comandas abertas', $orders->count(), 1),
    inv('recebimentos pendentes', MenuOrderReceipt::where('table_order_id', $orders->sole()->id)->where('status', 'pending')->count(), 20),
    inv('nenhum item cobrado', $orders->sole()->items()->count(), 0),
    inv('saldo preservado', $p3->fresh()->stock_quantity, '10.000'),
]);

// C4: fechamento simultâneo com client_uuid diferente (dois caixas/abas).
$table4 = DiningTable::factory()->create(['company_id' => $company->id, 'number' => 102]);
$o4 = app(OpenTable::class)->handle($table4, 1, $admin);
app(AddOrderItems::class)->handle($o4, [['product_id' => $p3->id, 'quantity' => 1, 'notes' => null]], $u());
$before = Sale::count();
$r = run_all(array_map(fn () => ['close', ['user' => $admin->id, 'order' => $o4->id, 'uuid' => (string) Str::uuid(), 'subtotal' => '10.00']], range(1, 6)));
report('C4 fechar mesma comanda 6x (uuids distintos)', $r, [
    inv('fechamentos aceitos', tally($r)['ok'], 1),
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
    inv('baixas aceitas', tally($r)['ok'], 1),
    inv('movimentos novos', CashMovement::count() - $before, 1),
    inv('status', $bill->fresh()->status, 'paid'),
]);

// C6: cancelamento simultâneo da mesma venda em dinheiro.
$p6 = $product();
$s6 = app(RegisterSale::class)->handle(sale_payload($p6->id, $u()))[0];
$before = CashMovement::count();
$r = run_all(array_fill(0, 6, ['cancel', ['user' => $admin->id, 'sale' => $s6->id]]));
report('C6 cancelar mesma venda 6x', $r, [
    inv('cancelamentos aceitos', tally($r)['ok'], 1),
    inv('sem movimento extra com caixa original aberto', CashMovement::count() - $before, 0),
    inv('movimento de estoque de reversao unico', StockMovement::where('sale_id', $s6->id)->where('type', StockMovement::SALE_REVERSAL)->count(), 1),
    inv('venda cancelada', $s6->fresh()->status, 'cancelled'),
    inv('saldo restaurado', $p6->fresh()->stock_quantity, '10.000'),
]);

// C7: abrir o caixa 8x para o mesmo operador.
$userC7 = User::factory()->forRole($role)->create();
$r = run_all(array_fill(0, 8, ['opencash', ['user' => $userC7->id]]));
report('C7 abrir caixa 8x', $r, [inv('aberturas aceitas', tally($r)['ok'], 1), inv('sessoes abertas', CashSession::where('open_user_id', $userC7->id)->count(), 1)]);

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

require __DIR__.'/financial.php';
require __DIR__.'/receipts.php';

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
    $closeUuids = [$u(), $u()];
    $stockBefore = $p8->fresh()->stock_quantity;
    $r = run_all([
        ['close', ['user' => $admin->id, 'order' => $o->id, 'uuid' => $closeUuids[0], 'subtotal' => '10.00', 'force_lock_overlap' => true]],
        ['transfer', ['user' => $admin->id, 'order' => $o->id, 'target' => $to->id, 'force_lock_overlap' => true]],
        ['close', ['user' => $admin->id, 'order' => $o->id, 'uuid' => $closeUuids[1], 'subtotal' => '10.00', 'force_lock_overlap' => true]],
        ['transfer', ['user' => $admin->id, 'order' => $o->id, 'target' => $to->id, 'force_lock_overlap' => true]],
    ]);
    foreach ($r as $x) {
        $all[] = $x;
        if (! $x['ok'] && ($x['code'] ?? null) === 1213) {
            $deadlocks++;
        }
        if (! $x['ok'] && ($x['code'] ?? null) === 1205) {
            $lockWait++;
        }
    }
    $o->refresh();
    // Consultar os dois UUIDs também encontra vendas órfãs ou duplicadas fora do vínculo da comanda.
    $sales = Sale::whereIn('client_uuid', $closeUuids)->get();
    $saleIds = $sales->modelKeys();
    $payments = SalePayment::whereIn('sale_id', $saleIds);
    $movements = StockMovement::where('product_id', $p8->id)->whereIn('sale_id', $saleIds);
    $consistentEffects = TableCloseIntegrity::isConsistent([
        'status' => $o->status, 'linked_sale_id' => $o->sale_id, 'sale_ids' => $saleIds,
        'completed_sales' => $sales->where('status', 'completed')->count(),
        'sale_total' => (string) $sales->sum('total'), 'payment_total' => (string) $payments->sum('amount'),
        'payments' => $payments->count(), 'sale_items' => DB::table('sale_items')->whereIn('sale_id', $saleIds)->count(),
        'stock_before' => $stockBefore, 'stock_after' => $p8->fresh()->stock_quantity,
        'movement_quantity' => (string) $movements->sum('quantity'), 'movements' => $movements->count(),
    ]);
    if (! in_array($o->status, ['closed', 'open'], true)
        || ! $consistentEffects
        || ($o->isOpen() && $o->open_table_id !== $o->dining_table_id)
        || (! $o->isOpen() && $o->open_table_id !== null)
        || TableOrder::whereIn('open_table_id', [$from->id, $to->id])->count() > 1) {
        $bad++;
    }
}
$unexpected = array_filter($all, fn ($result) => ! $result['ok']
    && ($result['class'] ?? '') !== 'ValidationException'
    && ! (in_array($result['class'] ?? '', ['QueryException', 'DeadlockException'], true) && ($result['code'] ?? null) === 1213));
$c9Pass = $bad === 0 && $unexpected === [] && $deadlocks === 0 && $lockWait === 0;
$failedScenarios += $c9Pass ? 0 : 1;
echo json_encode(['cenario' => 'C9 fechar x transferir, 30 rodadas, 4 workers', 'integridade' => $c9Pass ? 'PASSOU' : 'FALHOU', 'operacao' => $deadlocks ? 'FALHOU_AUD_020' : 'PASSOU_SEM_DEADLOCK', 'estados_invalidos' => $bad, 'erros_inesperados' => count($unexpected), 'deadlocks_1213' => $deadlocks, 'lock_wait_1205' => $lockWait, 'tally' => tally($all)], JSON_UNESCAPED_UNICODE)."\n";
exit($failedScenarios ? 1 : 0);
