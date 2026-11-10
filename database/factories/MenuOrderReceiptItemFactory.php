<?php

namespace Database\Factories;

use App\Models\MenuOrderReceipt;
use App\Models\MenuOrderReceiptItem;
use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MenuOrderReceiptItem>
 */
class MenuOrderReceiptItemFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'menu_order_receipt_id' => MenuOrderReceipt::factory(),
            'company_id' => fn (array $attributes) => MenuOrderReceipt::withoutGlobalScopes()->findOrFail($attributes['menu_order_receipt_id'])->company_id,
            'product_id' => fn (array $attributes) => Product::factory()->create(['company_id' => $attributes['company_id']])->id,
            'product_name' => 'Produto de teste', 'unit' => 'UN', 'quantity' => 1, 'unit_price' => '10.00', 'total' => '10.00', 'notes' => null,
        ];
    }
}
