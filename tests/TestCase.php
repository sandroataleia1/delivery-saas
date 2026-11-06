<?php

namespace Tests;

use App\Actions\OpenCashSession;
use App\Models\Company;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Tests\Support\DisposableDatabase;

abstract class TestCase extends BaseTestCase
{
    public function createApplication(): Application
    {
        $app = parent::createApplication();
        DisposableDatabase::guardApplication($app);

        return $app;
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }

    protected function openCash(User $user, string $amount = '0'): void
    {
        app(OpenCashSession::class)->handle($user, $amount);
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
