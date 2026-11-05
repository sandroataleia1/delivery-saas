<?php

namespace Database\Factories;

use App\Models\Company;
use App\Rules\Cnpj;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Company>
 */
class CompanyFactory extends Factory
{
    public function definition(): array
    {
        $name = fake()->company();

        return [
            'legal_name' => $name.' Ltda',
            'trade_name' => $name,
            'document' => Cnpj::generate(),
            'email' => fake()->unique()->safeEmail(),
            'phone' => '11999990000',
            'active' => true,
        ];
    }
}
