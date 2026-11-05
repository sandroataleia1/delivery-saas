<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\Role;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Role>
 */
class RoleFactory extends Factory
{
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'name' => 'Perfil '.fake()->unique()->numberBetween(1, 999999),
            'is_admin' => false,
            'permissions' => [],
        ];
    }

    public function admin(): static
    {
        return $this->state(fn () => ['name' => 'Administrador', 'is_admin' => true]);
    }
}
