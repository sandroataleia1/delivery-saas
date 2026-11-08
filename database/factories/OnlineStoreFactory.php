<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\OnlineStore;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OnlineStore>
 */
class OnlineStoreFactory extends Factory
{
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'enabled' => false,
            'slug' => 'loja-'.fake()->unique()->numberBetween(1000, 999999),
            'accepts_delivery' => true,
            'accepts_pickup' => true,
            'accept_minutes' => 15,
        ];
    }
}
