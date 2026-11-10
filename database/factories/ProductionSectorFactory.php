<?php

namespace Database\Factories;

use App\Models\Company;
use Illuminate\Database\Eloquent\Factories\Factory;

class ProductionSectorFactory extends Factory
{
    public function definition(): array
    {
        return ['company_id' => Company::factory(), 'name' => fake()->unique()->word(), 'active' => true];
    }
}
