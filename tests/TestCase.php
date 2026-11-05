<?php

namespace Tests;

use App\Models\Company;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }

    protected function adminOf(?Company $company = null): User
    {
        $role = Role::factory()->admin()->create(['company_id' => ($company ?? Company::factory()->create())->id]);

        return User::factory()->forRole($role)->create();
    }

    protected function userWith(array $permissions, ?Company $company = null): User
    {
        $role = Role::factory()->create([
            'company_id' => ($company ?? Company::factory()->create())->id,
            'permissions' => $permissions,
        ]);

        return User::factory()->forRole($role)->create();
    }
}
