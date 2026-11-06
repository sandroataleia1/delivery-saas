<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Product>
 */
class ProductFactory extends Factory
{
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'name' => 'Produto '.fake()->unique()->numberBetween(1, 999999),
            'unit' => 'UN',
            'sale_price' => '10.00',
            'cost_price' => '5.0000',
            'track_stock' => true,
            'stock_quantity' => '0.000',
            'min_stock' => '0.000',
            'active' => true,
        ];
    }
}
