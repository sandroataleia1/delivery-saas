<?php

namespace Database\Seeders;

use App\Actions\AdvanceDeliveryOrder;
use App\Actions\CancelSale;
use App\Actions\CloseCashSession;
use App\Actions\CreateBills;
use App\Actions\CreateDeliveryOrder;
use App\Actions\MoveStock;
use App\Actions\OpenCashSession;
use App\Actions\RecordCashMovement;
use App\Actions\RegisterSale;
use App\Actions\SettleBill;
use App\Actions\SettleDeliveryOrder;
use App\Models\Bill;
use App\Models\CashMovement;
use App\Models\CashSession;
use App\Models\Customer;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

/**
 * 45 dias de operação fictícia da Pizzaria Demo, para os relatórios terem o que
 * mostrar. Só para desenvolvimento: php artisan db:seed --class=DemoHistorySeeder
 * Passa pelas ações reais, então auditoria, estoque e caixa ficam coerentes.
 */
class DemoHistorySeeder extends Seeder
{
    private const DAYS = 45;

    public function run(): void
    {
        if (app()->isProduction()) {
            throw new \RuntimeException('Seeder de demonstração proibido em produção.');
        }

        mt_srand(2026);

        $today = today();
        $cashier = User::withoutGlobalScopes()->with('role')->where('email', 'caixa@delivery.test')->firstOrFail();
        $manager = User::withoutGlobalScopes()->with('role')->where('email', 'gerente@delivery.test')->firstOrFail();
        $courier = User::withoutGlobalScopes()->where('email', 'entregador@delivery.test')->firstOrFail();

        Auth::setUser($manager);
        $this->restock();

        for ($back = self::DAYS; $back >= 1; $back--) {
            $day = $today->copy()->subDays($back);
            $this->at($day, 10, 30);

            Auth::setUser($cashier);
            $session = app(OpenCashSession::class)->handle($cashier, '150');

            $this->sellDay($day, $cashier);
            $this->deliverDay($day, $courier);

            if ($day->dayOfWeek === Carbon::SATURDAY) {
                $this->at($day, 21, 50);
                app(RecordCashMovement::class)->handle($session, CashMovement::WITHDRAWAL, '200.00', 'Depósito no cofre');
            }

            $this->closeDay($day, $session);
            $this->paySomeBills($day, $manager);
        }

        Carbon::setTestNow();
    }

    private function at(Carbon $day, int $hour, int $minute = 0): void
    {
        Carbon::setTestNow($day->copy()->setTime($hour, $minute));
    }

    private function restock(): void
    {
        foreach (Product::where('track_stock', true)->get() as $product) {
            app(MoveStock::class)->handle($product, StockMovement::ADJUSTMENT_IN, '2000');
        }
    }

    private function sellable()
    {
        return Product::where('active', true)->where('sellable', true)->get();
    }

    private function sellDay(Carbon $day, User $cashier): void
    {
        $products = $this->sellable();
        $isWeekend = in_array($day->dayOfWeek, [Carbon::FRIDAY, Carbon::SATURDAY, Carbon::SUNDAY], true);
        $sales = mt_rand(5, 9) + ($isWeekend ? mt_rand(5, 9) : 0);
        $hours = [11, 12, 12, 13, 18, 19, 19, 20, 20, 20, 21, 21, 22];

        for ($index = 0; $index < $sales; $index++) {
            $this->at($day, $hours[mt_rand(0, 12)], mt_rand(0, 59));

            $product = $products[mt_rand(0, $products->count() - 1)];
            $quantity = mt_rand(1, 3);
            $total = bcmul((string) $quantity, $product->sale_price, 2);
            $method = ['cash', 'pix', 'pix', 'credit', 'debit'][mt_rand(0, 4)];
            $isTable = mt_rand(1, 3) === 1;

            [$sale] = app(RegisterSale::class)->handle([
                'client_uuid' => (string) Str::uuid(),
                'items' => [['product_id' => $product->id, 'quantity' => (string) $quantity, 'expected_price' => $product->sale_price]],
                'payments' => [['method' => $method, 'amount' => $total]],
                'origin' => $isTable ? 'table' : 'counter',
                'table_number' => $isTable ? mt_rand(1, 8) : null,
            ]);

            if (mt_rand(1, 40) === 1) {
                app(CancelSale::class)->handle($sale, 'Pedido lançado errado');
            }
        }
    }

    private function deliverDay(Carbon $day, User $courier): void
    {
        $customer = Customer::with('addresses')->firstOrFail();
        $pizza = Product::where('name', 'Pizza Margherita')->firstOrFail();

        foreach (range(1, mt_rand(2, 5)) as $index) {
            $this->at($day, mt_rand(18, 21), mt_rand(0, 25));
            $district = ['Centro', 'Jardim América', 'Vila Nova'][mt_rand(0, 2)];
            $fee = ['Centro' => '5.00', 'Jardim América' => '8.00', 'Vila Nova' => '10.00'][$district];

            $order = app(CreateDeliveryOrder::class)->handle([
                'client_uuid' => (string) Str::uuid(),
                'customer_id' => $customer->id,
                'type' => 'delivery',
                'items' => [['product_id' => $pizza->id, 'quantity' => '1']],
                'address' => ['street' => 'Rua das Flores', 'number' => (string) mt_rand(1, 900), 'district' => $district],
                'delivery_fee' => $fee,
                'payment_method' => 'pix',
            ]);

            foreach ([DeliveryOrderStage::RECEIVED, DeliveryOrderStage::PREPARING, DeliveryOrderStage::READY, DeliveryOrderStage::OUT] as $stage) {
                Carbon::setTestNow(now()->addMinutes(mt_rand(7, 16)));
                app(AdvanceDeliveryOrder::class)->handle($order, $stage, $stage === DeliveryOrderStage::READY ? $courier : null);
            }

            app(SettleDeliveryOrder::class)->handle($order, [
                'client_uuid' => (string) Str::uuid(),
                'payments' => [['method' => 'pix', 'amount' => bcadd($order->fresh()->total(), '0', 2)]],
            ]);
        }
    }

    private function closeDay(Carbon $day, CashSession $session): void
    {
        $this->at($day, 23, 10);

        $expected = $session->fresh()->calculateSummary()['expected_cash'];
        $slip = mt_rand(1, 6) === 1 ? ['-5.00', '-12.50', '3.00'][mt_rand(0, 2)] : '0';

        app(CloseCashSession::class)->handle(
            $session,
            bcadd($expected, $slip, 2),
            bccomp($slip, '0', 2) === 0 ? null : 'Diferença na conferência',
        );
    }

    private function paySomeBills(Carbon $day, User $manager): void
    {
        Auth::setUser($manager);

        foreach ([1 => ['Aluguel da loja', 'Aluguel', '2500.00'], 10 => ['Conta de energia', 'Energia', '420.00'], 15 => ['Folha de pagamento', 'Salários', '3800.00']] as $dayOfMonth => [$description, $category, $amount]) {
            if ($day->day !== $dayOfMonth) {
                continue;
            }

            $this->at($day, 9);

            $bill = app(CreateBills::class)->handle([
                'type' => Bill::PAYABLE,
                'description' => $description,
                'category' => $category,
                'amount' => $amount,
                'due_date' => $day->format('Y-m-d'),
            ])->first();

            app(SettleBill::class)->handle($bill, [
                'paid_at' => $day->format('Y-m-d'),
                'paid_amount' => $amount,
                'payment_method' => 'pix',
            ]);
        }
    }
}

/** Etapas na ordem em que o pedido avança. */
final class DeliveryOrderStage
{
    public const RECEIVED = 'received';

    public const PREPARING = 'preparing';

    public const READY = 'ready';

    public const OUT = 'out_for_delivery';
}
