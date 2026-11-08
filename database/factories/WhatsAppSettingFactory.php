<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\WhatsAppSetting;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WhatsAppSetting>
 */
class WhatsAppSettingFactory extends Factory
{
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'enabled' => false,
            'instance_name' => 'loja-'.fake()->unique()->numberBetween(1000, 999999),
            'instance_token' => fake()->sha1(),
            'state' => 'open',
            'state_checked_at' => now(),
        ];
    }
}
