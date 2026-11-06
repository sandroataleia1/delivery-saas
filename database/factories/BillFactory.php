<?php

namespace Database\Factories;

use App\Models\Bill;
use App\Models\Company;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Bill>
 */
class BillFactory extends Factory
{
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'type' => Bill::PAYABLE,
            'description' => 'Conta de teste',
            'category' => 'Outros',
            'amount' => '100.00',
            'due_date' => today()->addDays(5),
            'status' => Bill::OPEN,
            'user_name' => 'Sistema',
        ];
    }
}
