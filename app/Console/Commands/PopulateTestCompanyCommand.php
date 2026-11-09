<?php

namespace App\Console\Commands;

use App\Actions\CancelSale;
use App\Actions\CloseCashSession;
use App\Actions\CreateBills;
use App\Actions\MoveStock;
use App\Actions\OpenCashSession;
use App\Actions\RecordCashMovement;
use App\Actions\RegisterSale;
use App\Actions\SavePizzaSetup;
use App\Actions\SettleBill;
use App\Actions\SyncRecipe;
use App\Models\Bill;
use App\Models\CashMovement;
use App\Models\CashSession;
use App\Models\Category;
use App\Models\Company;
use App\Models\Customer;
use App\Models\DeliveryZone;
use App\Models\DiningTable;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\User;
use App\Support\ItemComposer;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

/**
 * Popula uma empresa de teste com cardápio (lanches, porções, bebidas e pizza por tamanho), clientes,
 * mesas, zonas de entrega, contas e dias de caixa com vendas. Passa pelas ações reais: estoque, caixa
 * e auditoria ficam coerentes. Roda uma vez por empresa: o cardápio de teste já existente barra a repetição.
 */
#[Signature('demo:populate {company : Id da empresa de teste} {--days=30 : Dias de vendas para trás}')]
#[Description('Popula uma empresa de teste com produtos, clientes, vendas e contas')]
class PopulateTestCompanyCommand extends Command
{
    public const MARKER_PRODUCT = 'X-Burguer';

    /** @var array<string, Product> */
    private array $products = [];

    private ?Product $pizza = null;

    public function handle(): int
    {
        $company = Company::find($this->argument('company'));

        if ($company === null) {
            $this->error('Empresa não encontrada.');

            return self::FAILURE;
        }

        $admin = User::with('role')->where('company_id', $company->id)->whereHas('role', fn ($query) => $query->where('name', 'Administrador'))->first();

        if ($admin === null) {
            $this->error('A empresa não tem usuário administrador.');

            return self::FAILURE;
        }

        if (Product::withoutGlobalScopes()->where('company_id', $company->id)->where('name', self::MARKER_PRODUCT)->exists()) {
            $this->error("A empresa {$company->trade_name} já foi populada.");

            return self::FAILURE;
        }

        if (CashSession::withoutGlobalScopes()->where('company_id', $company->id)->whereNull('closed_at')->exists()) {
            $this->error('Feche o caixa aberto da empresa antes de popular.');

            return self::FAILURE;
        }

        mt_srand(2026);
        Auth::setUser($admin);

        try {
            $this->catalog($company->id);
            $this->registers($company->id);
            $days = max(1, (int) $this->option('days'));
            $sales = $this->history($admin, $days);
            $this->openBills();
        } finally {
            Carbon::setTestNow();
        }

        $this->info("{$company->trade_name}: ".count($this->products)." produtos, {$sales} vendas em {$days} dias.");

        return self::SUCCESS;
    }

    private function catalog(int $companyId): void
    {
        $categories = [];

        foreach (['Lanches comuns', 'Lanches Especiais', 'Pizza', 'Porçoes', 'Bebidas', 'Insumos'] as $name) {
            $categories[$name] = Category::firstOrCreate(['company_id' => $companyId, 'name' => $name])->id;
        }

        foreach ([
            ['Pão de hambúrguer', 'Insumos', 'UN', '0', '0.9000', '1500'],
            ['Hambúrguer 120g', 'Insumos', 'UN', '0', '3.2000', '1500'],
            ['Queijo mussarela', 'Insumos', 'KG', '0', '34.9000', '120'],
            ['Bacon', 'Insumos', 'KG', '0', '39.9000', '60'],
            ['Batata congelada', 'Insumos', 'KG', '0', '14.5000', '300'],
            ['Coca-Cola 2L', 'Bebidas', 'UN', '14.00', '8.5000', '300'],
            ['Coca-Cola lata', 'Bebidas', 'UN', '6.00', '3.1000', '400'],
            ['Guaraná 2L', 'Bebidas', 'UN', '12.00', '6.9000', '300'],
            ['Água mineral', 'Bebidas', 'UN', '4.00', '1.2000', '300'],
        ] as [$name, $category, $unit, $price, $cost, $stock]) {
            $product = $this->product($companyId, $categories[$category], $name, $price, $unit, trackStock: true, sellable: $category !== 'Insumos');
            $product->cost_price = $cost;
            $product->save();
            app(MoveStock::class)->handle($product, StockMovement::INITIAL, $stock);
        }

        $burger = [['Pão de hambúrguer', '1'], ['Hambúrguer 120g', '1'], ['Queijo mussarela', '0.030']];

        foreach ([
            ['X-Burguer', 'Lanches comuns', '18.00', $burger],
            ['X-Salada', 'Lanches comuns', '20.00', $burger],
            ['X-Egg', 'Lanches comuns', '21.00', $burger],
            ['Misto quente', 'Lanches comuns', '12.00', [['Queijo mussarela', '0.040']]],
            ['Hot dog', 'Lanches comuns', '15.00', []],
            ['X-Bacon', 'Lanches Especiais', '28.00', [...$burger, ['Bacon', '0.050']]],
            ['X-Tudo', 'Lanches Especiais', '34.00', [...$burger, ['Bacon', '0.050']]],
            ['X-Frango com catupiry', 'Lanches Especiais', '30.00', [['Pão de hambúrguer', '1'], ['Queijo mussarela', '0.030']]],
            ['Batata frita', 'Porçoes', '30.00', [['Batata congelada', '0.400']]],
            ['Calabresa acebolada', 'Porçoes', '35.00', []],
            ['Frango a passarinho', 'Porçoes', '38.00', []],
            ['Suco natural 500ml', 'Bebidas', '9.00', []],
        ] as [$name, $category, $price, $recipe]) {
            $product = $this->product($companyId, $categories[$category], $name, $price, 'UN', trackStock: false, sellable: true);
            app(SyncRecipe::class)->handle($product, array_map(fn (array $line) => [
                'ingredient_id' => $this->products[$line[0]]->id,
                'quantity' => $line[1],
            ], $recipe));
        }

        $this->pizza = $this->product($companyId, $categories['Pizza'], 'Pizza', '0', 'UN', trackStock: false, sellable: true);
        app(SavePizzaSetup::class)->handle($this->pizza, [
            'pizza_pricing' => 'highest',
            'sizes' => [
                ['name' => 'Pequena', 'slices' => 4, 'max_flavors' => 1, 'active' => true],
                ['name' => 'Média', 'slices' => 6, 'max_flavors' => 2, 'active' => true],
                ['name' => 'Grande', 'slices' => 8, 'max_flavors' => 3, 'active' => true],
            ],
            'flavors' => array_map(fn (array $flavor) => [
                'name' => $flavor[0],
                'active' => true,
                'prices' => ['n0' => $flavor[1], 'n1' => $flavor[2], 'n2' => $flavor[3]],
            ], [
                ['Mussarela', '35.00', '45.00', '55.00'],
                ['Calabresa', '36.00', '47.00', '58.00'],
                ['Marguerita', '38.00', '49.00', '60.00'],
                ['Portuguesa', '40.00', '52.00', '64.00'],
                ['Frango com catupiry', '40.00', '52.00', '64.00'],
                ['Quatro queijos', '42.00', '55.00', '68.00'],
                ['Chocolate', '38.00', '48.00', '58.00'],
            ]),
        ]);
        $this->pizza->refresh();
    }

    private function product(int $companyId, int $categoryId, string $name, string $price, string $unit, bool $trackStock, bool $sellable): Product
    {
        $product = new Product(['category_id' => $categoryId, 'name' => $name, 'unit' => $unit, 'sale_price' => $price, 'track_stock' => $trackStock]);
        $product->company_id = $companyId;
        $product->sellable = $sellable;
        $product->active = true;
        $product->save();

        return $this->products[$name] = $product;
    }

    /** Clientes com endereço, zonas de entrega, mesas e fornecedor. */
    private function registers(int $companyId): void
    {
        foreach (['Centro' => '5.00', 'Jardim Primavera' => '7.00', 'Vila Nova' => '9.00'] as $name => $fee) {
            $zone = new DeliveryZone(['name' => $name, 'fee' => $fee]);
            $zone->company_id = $companyId;
            $zone->save();
        }

        foreach (range(1, 8) as $number) {
            $table = new DiningTable(['number' => $number, 'area' => $number <= 6 ? 'Salão' : 'Varanda', 'seats' => 4]);
            $table->company_id = $companyId;
            $table->active = true;
            $table->save();
        }

        $supplier = new Supplier(['name' => 'Distribuidora Teste', 'phone' => '1133334444']);
        $supplier->company_id = $companyId;
        $supplier->active = true;
        $supplier->save();

        foreach ([
            ['Ana Souza', '11991110001', 'Rua das Flores', '120', 'Centro'],
            ['Bruno Lima', '11991110002', 'Avenida Brasil', '455', 'Centro'],
            ['Carla Mendes', '11991110003', 'Rua São João', '89', 'Jardim Primavera'],
            ['Diego Rocha', '11991110004', 'Rua das Palmeiras', '310', 'Jardim Primavera'],
            ['Eduarda Alves', '11991110005', 'Rua Sete de Setembro', '42', 'Vila Nova'],
            ['Felipe Costa', '11991110006', 'Rua XV de Novembro', '777', 'Vila Nova'],
            ['Gabriela Martins', '11991110007', 'Rua Tiradentes', '15', 'Centro'],
            ['Henrique Dias', '11991110008', 'Rua Santos Dumont', '204', 'Jardim Primavera'],
            ['Isabela Ferreira', '11991110009', 'Rua Rio Branco', '63', 'Vila Nova'],
            ['João Pereira', '11991110010', 'Rua Marechal Deodoro', '501', 'Centro'],
        ] as [$name, $phone, $street, $number, $district]) {
            $customer = new Customer(['name' => $name, 'phone' => $phone]);
            $customer->company_id = $companyId;
            $customer->active = true;
            $customer->save();

            $address = $customer->addresses()->make(['street' => $street, 'number' => $number, 'district' => $district]);
            $address->company_id = $companyId;
            $address->save();
        }
    }

    /** Um caixa por dia, com vendas no balcão e nas mesas, sangria aos sábados e contas fixas pagas. */
    private function history(User $admin, int $days): int
    {
        $sales = 0;

        for ($back = $days; $back >= 1; $back--) {
            $day = today()->subDays($back);
            $this->at($day, 10, 30);
            $session = app(OpenCashSession::class)->handle($admin, '150');

            $sales += $this->sellDay($day);

            if ($day->dayOfWeek === Carbon::SATURDAY) {
                $this->at($day, 21, 50);
                app(RecordCashMovement::class)->handle($session, CashMovement::WITHDRAWAL, '200.00', 'Depósito no cofre');
            }

            $this->at($day, 23, 10);
            $expected = $session->fresh()->calculateSummary()['expected_cash'];
            app(CloseCashSession::class)->handle($session, $expected, null);
            $this->payFixedBills($day);
        }

        return $sales;
    }

    private function sellDay(Carbon $day): int
    {
        $isWeekend = in_array($day->dayOfWeek, [Carbon::FRIDAY, Carbon::SATURDAY, Carbon::SUNDAY], true);
        $count = mt_rand(6, 10) + ($isWeekend ? mt_rand(6, 10) : 0);
        $simple = collect($this->products)->filter(fn (Product $product) => $product->sellable && ! $product->is($this->pizza))->values();
        $sizes = $this->pizza->pizzaSizes()->orderBy('position')->get();
        $flavorIds = $this->pizza->optionGroups()->sole()->options()->pluck('id')->all();
        $hours = [11, 12, 12, 13, 18, 19, 19, 20, 20, 20, 21, 21, 22];

        for ($index = 0; $index < $count; $index++) {
            $this->at($day, $hours[mt_rand(0, 12)], mt_rand(0, 59));
            $items = [];

            foreach (range(1, mt_rand(1, 3)) as $line) {
                if (mt_rand(1, 3) === 1) {
                    $size = $sizes[mt_rand(0, $sizes->count() - 1)];
                    shuffle($flavorIds);
                    $items[] = ['product_id' => $this->pizza->id, 'quantity' => '1', 'size_id' => $size->id, 'flavor_ids' => array_slice($flavorIds, 0, mt_rand(1, $size->max_flavors)), 'options' => []];
                } else {
                    $items[] = ['product_id' => $simple[mt_rand(0, $simple->count() - 1)]->id, 'quantity' => (string) mt_rand(1, 2), 'options' => []];
                }
            }

            $composed = app(ItemComposer::class)->composeMany($this->pizza->company_id, $items);
            $total = '0.00';

            foreach ($items as $position => $item) {
                $items[$position]['expected_price'] = $composed[$position]['unit_price'];
                $total = bcadd($total, bcmul($composed[$position]['unit_price'], $item['quantity'], 2), 2);
            }

            $isTable = mt_rand(1, 3) === 1;
            [$sale] = app(RegisterSale::class)->handle([
                'client_uuid' => (string) Str::uuid(),
                'items' => $items,
                'payments' => [['method' => ['cash', 'pix', 'pix', 'credit', 'debit'][mt_rand(0, 4)], 'amount' => $total]],
                'origin' => $isTable ? 'table' : 'counter',
                'table_number' => $isTable ? mt_rand(1, 8) : null,
            ]);

            if (mt_rand(1, 40) === 1) {
                app(CancelSale::class)->handle($sale, 'Pedido lançado errado');
            }
        }

        return $count;
    }

    private function payFixedBills(Carbon $day): void
    {
        foreach ([5 => ['Aluguel da loja', 'Aluguel', '1800.00'], 12 => ['Conta de energia', 'Energia', '390.00']] as $dayOfMonth => [$description, $category, $amount]) {
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

            app(SettleBill::class)->handle($bill, ['paid_at' => $day->format('Y-m-d'), 'paid_amount' => $amount, 'payment_method' => 'pix']);
        }
    }

    private function openBills(): void
    {
        Carbon::setTestNow();

        foreach ([
            [Bill::PAYABLE, 'Compra de bebidas', 'Fornecedores', '640.00', 7, 'Distribuidora Teste'],
            [Bill::RECEIVABLE, 'Encomenda de salgados para festa', 'Eventos e encomendas', '350.00', 3, 'Ana Souza'],
        ] as [$type, $description, $category, $amount, $days, $counterparty]) {
            app(CreateBills::class)->handle([
                'type' => $type,
                'description' => $description,
                'category' => $category,
                'amount' => $amount,
                'due_date' => today()->addDays($days)->format('Y-m-d'),
                'counterparty' => $counterparty,
            ]);
        }
    }

    private function at(Carbon $day, int $hour, int $minute = 0): void
    {
        Carbon::setTestNow($day->copy()->setTime($hour, $minute));
    }
}
