<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\RestaurantOperationSettings;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RestaurantOperationSettings>
 */
class RestaurantOperationSettingsFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            ...config('restaurant.defaults'),
        ];
    }
}
