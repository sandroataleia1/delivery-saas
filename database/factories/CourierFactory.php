<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\Courier;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Courier> */
class CourierFactory extends Factory
{
    public function definition(): array
    {
        return ['company_id' => Company::factory(), 'name' => fake()->name(), 'phone' => null, 'active' => true, 'user_id' => null];
    }
}
