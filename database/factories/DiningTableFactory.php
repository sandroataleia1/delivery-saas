<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\DiningTable;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DiningTable>
 */
class DiningTableFactory extends Factory
{
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'number' => fake()->unique()->numberBetween(1, 9999),
            'seats' => 4,
            'active' => true,
        ];
    }
}
