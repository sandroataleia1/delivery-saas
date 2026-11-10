<?php

/** Executado pelo orquestrador protegido run.php, usando sua instância descartável. */

use App\Actions\CreateBills;
use App\Actions\RegisterSale;
use App\Actions\RegisterStockEntry;
use App\Actions\SettleBill;
use App\Models\Bill;
use App\Models\CashMovement;
use App\Models\Customer;
use App\Models\StockMovement;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

Auth::setUser($admin);
$partial = Bill::factory()->create(['company_id' => $company->id, 'type' => Bill::RECEIVABLE, 'amount' => '80']);
$cashBefore = $cash->calculateSummary()['expected_cash'];
$movementBefore = CashMovement::count();
$r = run_all(array_fill(0, 8, ['settle', ['user' => $admin->id, 'bill' => $partial->id, 'amount' => '30.00']]));
report('F1 oito baixas de 30 disputam saldo 80', $r, [
    inv('baixas aceitas', tally($r)['ok'], 2),
    inv('valor efetivo', $partial->fresh()->paid_amount, '60.00'),
    inv('saldo restante', $partial->fresh()->balance(), '20.00'),
    inv('ledger', $partial->settlements()->count(), 2),
    inv('movimentos', CashMovement::count() - $movementBefore, 2),
    inv('caixa recebeu somente 60', $cash->calculateSummary()['expected_cash'], bcadd($cashBefore, '60', 2)),
    inv('auditoria de baixas', DB::table('audit_logs')->where('auditable_type', 'BillSettlement')->where('event', 'created')->whereIn('auditable_id', $partial->settlements()->pluck('id'))->count(), 2),
]);
app(SettleBill::class)->handle($partial, ['paid_at' => today()->format('Y-m-d'), 'paid_amount' => '20', 'payment_method' => 'cash']);

$discounted = Bill::factory()->create(['company_id' => $company->id, 'type' => Bill::RECEIVABLE, 'amount' => '80']);
$r = run_all(array_fill(0, 6, ['settle', ['user' => $admin->id, 'bill' => $discounted->id, 'amount' => '70', 'discount' => '10', 'discount_reason' => 'Acordo concorrente']]));
report('F2 seis quitações com desconto disputam saldo 80', $r, [
    inv('quitação aceita', tally($r)['ok'], 1),
    inv('recebido', $discounted->fresh()->paid_amount, '70.00'),
    inv('desconto explícito', $discounted->fresh()->discount_amount, '10.00'),
    inv('saldo', $discounted->fresh()->balance(), '0.00'),
    inv('ledger', $discounted->settlements()->count(), 1),
]);

$selected = $discounted->settlements()->sole();
$cashBefore = $cash->calculateSummary()['expected_cash'];
$movementBefore = CashMovement::count();
$r = run_all(array_fill(0, 6, ['reopenbill', ['user' => $admin->id, 'bill' => $discounted->id, 'settlement' => $selected->id]]));
report('F3 seis estornos da mesma baixa selecionada', $r, [
    inv('estorno aceito', tally($r)['ok'], 1),
    inv('saldo restaurado com desconto revertido', $discounted->fresh()->balance(), '80.00'),
    inv('movimento contrário único', CashMovement::count() - $movementBefore, 1),
    inv('caixa devolveu somente 70', $cash->calculateSummary()['expected_cash'], bcsub($cashBefore, '70', 2)),
    inv('ledger preservado', $discounted->settlements()->count(), 1),
    inv('baixa marcada estornada', $selected->fresh()->reversed_at !== null ? 1 : 0, 1),
]);

$customer = Customer::factory()->create(['company_id' => $company->id, 'allow_credit' => true, 'credit_limit' => '99999']);
foreach (['sale', 'entry'] as $originType) {
    $results = [];
    $invalid = 0;
    $cancelledRounds = 0;
    $settledRounds = 0;
    foreach (range(1, 10) as $round) {
        Auth::setUser($admin);
        $item = $product(['sale_price' => '80', 'stock_quantity' => '10']);
        if ($originType === 'sale') {
            $payload = sale_payload($item->id, $u(), '80.00', 'fiado');
            $payload['customer_id'] = $customer->id;
            [$origin] = app(RegisterSale::class)->handle($payload);
            $bill = Bill::where('sale_id', $origin->id)->sole();
            $cancelJob = ['cancel', ['user' => $admin->id, 'sale' => $origin->id]];
        } else {
            // Mesmas Actions e transação do controller, com movimentos reais de estoque.
            $origin = DB::transaction(function () use ($item) {
                $entry = app(RegisterStockEntry::class)->handle(
                    ['entry_date' => today()->format('Y-m-d')],
                    [['product_id' => $item->id, 'quantity' => '1', 'unit_cost' => '80']],
                );
                app(CreateBills::class)->handle([
                    'type' => Bill::PAYABLE, 'description' => 'Entrada concorrente', 'category' => 'Fornecedores',
                    'stock_entry_id' => $entry->id, 'amount' => '80', 'due_date' => today()->format('Y-m-d'),
                ]);

                return $entry;
            });
            $bill = Bill::where('stock_entry_id', $origin->id)->sole();
            $cancelJob = ['cancelentry', ['user' => $admin->id, 'entry' => $origin->id]];
        }
        $cashBefore = $cash->calculateSummary()['expected_cash'];
        // Alternar quem obtém o lock primeiro cobre os dois desfechos possíveis da disputa.
        $settleJob = ['settle', ['user' => $admin->id, 'bill' => $bill->id, 'amount' => '1']];
        if ($round % 2 === 0) {
            $cancelJob[1]['delay_after_barrier_us'] = 300000;
        } else {
            $settleJob[1]['delay_after_barrier_us'] = 300000;
        }
        $r = run_all([
            $settleJob,
            $cancelJob,
        ]);
        array_push($results, ...$r);
        $origin->refresh();
        $bill->refresh();
        $cancelled = $origin->isCancelled();
        $cancelledRounds += $cancelled ? 1 : 0;
        $settledRounds += $cancelled ? 0 : 1;
        $received = $bill->settlements()->count();
        $direction = $originType === 'sale' ? '1' : '-1';
        $consistent = tally($r)['ok'] === 1
            && ($cancelled ? $bill->status === Bill::CANCELLED && $received === 0 : $bill->status === Bill::OPEN && $received === 1 && $bill->balance() === '79.00')
            && $item->fresh()->stock_quantity === ($cancelled ? '10.000' : ($originType === 'sale' ? '9.000' : '11.000'))
            && StockMovement::where('product_id', $item->id)->count() === ($cancelled ? 2 : 1)
            && $cash->calculateSummary()['expected_cash'] === ($cancelled ? $cashBefore : bcadd($cashBefore, $direction, 2));
        $invalid += $consistent ? 0 : 1;
    }
    report($originType === 'sale' ? 'F4 baixa parcial x cancelamento de venda, 10 rodadas' : 'F5 baixa parcial x cancelamento de entrada, 10 rodadas', $results, [
        inv('estados inconsistentes', $invalid, 0),
        inv('cancelamento vence em alguma rodada', $cancelledRounds > 0 ? 1 : 0, 1),
        inv('baixa vence em alguma rodada', $settledRounds > 0 ? 1 : 0, 1),
    ]);
}
