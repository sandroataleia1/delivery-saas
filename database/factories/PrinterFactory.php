<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\Printer;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Printer>
 */
class PrinterFactory extends Factory
{
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'name' => fake()->unique()->word(),
            'connection' => Printer::NETWORK,
            'address' => fake()->localIpv4(),
            'port' => Printer::DEFAULT_PORT,
            'columns' => 48,
            'active' => true,
        ];
    }

    public function windows(): static
    {
        return $this->state(['connection' => Printer::WINDOWS, 'address' => '\\CAIXA\Cozinha', 'port' => null]);
    }
}
