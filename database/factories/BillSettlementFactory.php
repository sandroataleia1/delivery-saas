<?php

namespace Database\Factories;

use App\Models\Bill;
use App\Models\BillSettlement;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BillSettlement>
 */
class BillSettlementFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'bill_id' => Bill::factory(),
            'company_id' => fn (array $attributes) => Bill::withoutGlobalScopes()->findOrFail($attributes['bill_id'])->company_id,
            'amount' => '10.00', 'discount_amount' => '0.00',
            'paid_at' => today(), 'payment_method' => 'pix', 'user_name' => 'Sistema',
        ];
    }
}
