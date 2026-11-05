<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    protected static ?string $password;

    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
            'active' => true,
        ];
    }

    public function forRole(Role $role): static
    {
        return $this->state(fn () => ['company_id' => $role->company_id, 'role_id' => $role->id]);
    }

    public function superAdmin(): static
    {
        return $this->state(fn () => ['company_id' => null, 'role_id' => null, 'is_super_admin' => true]);
    }
}
