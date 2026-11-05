<?php

namespace Database\Seeders;

use App\Actions\CreateCompany;
use App\Actions\MoveStock;
use App\Models\Category;
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
        ] as $roleName => [$name, $email]) {
            $user = new User(['name' => $name, 'email' => $email, 'password' => 'password']);
            $user->company_id = $company->id;
            $user->role_id = Role::where('company_id', $company->id)->where('name', $roleName)->value('id');
            $user->save();
        }

        $this->seedStock($company->id);
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
            $product->save();

            if ($track) {
                app(MoveStock::class)->handle($product, StockMovement::INITIAL, $stock);
            }
        }
    }
}
