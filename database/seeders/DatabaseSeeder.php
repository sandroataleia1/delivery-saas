<?php

namespace Database\Seeders;

use App\Actions\CreateBills;
use App\Actions\CreateCompany;
use App\Actions\MoveStock;
use App\Actions\SyncRecipe;
use App\Models\Bill;
use App\Models\Category;
use App\Models\Customer;
use App\Models\DeliveryZone;
use App\Models\DiningTable;
use App\Models\Product;
use App\Models\Role;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Database\Seeder;

/** Dados de desenvolvimento. Nunca rode em produção: as senhas são públicas. */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $superAdmin = new User([
            'name' => 'Super Admin',
            'email' => 'super@delivery.test',
            'password' => 'password',
        ]);
        $superAdmin->is_super_admin = true;
        $superAdmin->save();

        $company = app(CreateCompany::class)->handle(
            [
                'legal_name' => 'Pizzaria Demonstração Ltda',
                'trade_name' => 'Pizzaria Demo',
                'document' => '11222333000181',
                'email' => 'contato@pizzariademo.test',
                'phone' => '11988887777',
            ],
            ['name' => 'Ana Administradora', 'email' => 'admin@delivery.test', 'password' => 'password'],
            mustChangePassword: false,
        );

        foreach ([
            'Gerente' => ['Gabriel Gerente', 'gerente@delivery.test'],
            'Caixa' => ['Carla Caixa', 'caixa@delivery.test'],
            'Garçom' => ['Gustavo Garçom', 'garcom@delivery.test'],
            'Entregador' => ['Elias Entregador', 'entregador@delivery.test'],
        ] as $roleName => [$name, $email]) {
            $user = new User(['name' => $name, 'email' => $email, 'password' => 'password']);
            $user->company_id = $company->id;
            $user->role_id = Role::where('company_id', $company->id)->where('name', $roleName)->value('id');
            $user->save();
        }

        $this->seedStock($company->id);
        $this->seedBills($company->id);
        $this->seedTables($company->id);
        $this->seedDelivery($company->id);
    }

    private function seedDelivery(int $companyId): void
    {
        foreach (['Centro' => '5.00', 'Jardim América' => '8.00', 'Vila Nova' => '10.00'] as $name => $fee) {
            $zone = new DeliveryZone(['name' => $name, 'fee' => $fee]);
            $zone->company_id = $companyId;
            $zone->save();
        }

        $customer = new Customer([
            'name' => 'Marcos Almeida',
            'phone' => '(11) 97777-6666',
            'allow_credit' => true,
            'credit_limit' => '300.00',
            'credit_days' => 30,
        ]);
        $customer->company_id = $companyId;
        $customer->active = true;
        $customer->save();

        $address = $customer->addresses()->make(['street' => 'Rua das Flores', 'number' => '120', 'district' => 'Centro', 'reference' => 'Casa de portão verde']);
        $address->company_id = $companyId;
        $address->save();
    }

    private function seedTables(int $companyId): void
    {
        foreach (range(1, 8) as $number) {
            $table = new DiningTable(['number' => $number, 'area' => $number <= 6 ? 'Salão' : 'Varanda', 'seats' => 4]);
            $table->company_id = $companyId;
            $table->active = true;
            $table->save();
        }
    }

    private function seedBills(int $companyId): void
    {
        foreach ([
            [Bill::PAYABLE, 'Conta de energia', 'Energia', '380.50', -2, null],
            [Bill::PAYABLE, 'Aluguel da loja', 'Aluguel', '2500.00', 5, null],
            [Bill::RECEIVABLE, 'Encomenda para evento', 'Eventos e encomendas', '640.00', 3, 'Escola Modelo'],
        ] as [$type, $description, $category, $amount, $days, $counterparty]) {
            app(CreateBills::class)->handle([
                'type' => $type,
                'description' => $description,
                'category' => $category,
                'amount' => $amount,
                'due_date' => today()->addDays($days)->format('Y-m-d'),
                'counterparty' => $counterparty,
            ], companyId: $companyId);
        }
    }

    private function seedStock(int $companyId): void
    {
        $supplier = new Supplier(['name' => 'Distribuidora Boa Compra', 'document' => '11444777000161', 'phone' => '1133334444']);
        $supplier->company_id = $companyId;
        $supplier->active = true;
        $supplier->save();

        $categories = [];

        foreach (['Bebidas', 'Insumos', 'Pizzas'] as $name) {
            $category = new Category(['name' => $name]);
            $category->company_id = $companyId;
            $category->save();
            $categories[$name] = $category->id;
        }

        $products = [];

        foreach ([
            ['Coca-Cola 2L', 'Bebidas', 'UN', '12.00', '7.5000', true, '24', '6', '7894900011517'],
            ['Guaraná 2L', 'Bebidas', 'UN', '10.00', '6.0000', true, '4', '6', '7891991000833'],
            ['Farinha de trigo', 'Insumos', 'KG', '0.00', '4.2000', true, '18.5', '10', null],
            ['Mussarela', 'Insumos', 'KG', '0.00', '32.9000', true, '3.2', '5', null],
            ['Pizza Margherita', 'Pizzas', 'UN', '45.00', '0.0000', false, '0', '0', null],
        ] as [$name, $category, $unit, $price, $cost, $track, $stock, $minimum, $barcode]) {
            $product = new Product([
                'category_id' => $categories[$category],
                'name' => $name,
                'unit' => $unit,
                'sale_price' => $price,
                'track_stock' => $track,
                'min_stock' => $minimum,
                'barcode' => $barcode,
            ]);
            $product->company_id = $companyId;
            $product->cost_price = $cost;
            $product->active = true;
            $product->sellable = $category !== 'Insumos';
            $product->save();

            if ($track) {
                app(MoveStock::class)->handle($product, StockMovement::INITIAL, $stock);
            }

            $products[$name] = $product;
        }

        app(SyncRecipe::class)->handle($products['Pizza Margherita'], [
            ['ingredient_id' => $products['Mussarela']->id, 'quantity' => '0.250'],
            ['ingredient_id' => $products['Farinha de trigo']->id, 'quantity' => '0.300'],
        ]);
    }
}
