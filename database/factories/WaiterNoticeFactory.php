<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\User;
use App\Models\WaiterNotice;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WaiterNotice>
 */
class WaiterNoticeFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'user_id' => fn (array $attributes) => User::factory()->create(['company_id' => $attributes['company_id']])->id,
            'kind' => WaiterNotice::READY,
            'title' => 'Mesa '.fake()->numberBetween(1, 40).': 1 item pronto — Cozinha',
            'body' => '1× '.fake()->word(),
        ];
    }
}
