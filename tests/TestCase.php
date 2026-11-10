<?php

namespace Tests;

use App\Actions\OpenCashSession;
use App\Actions\TransitionProduction;
use App\Models\Company;
use App\Models\ProductionSector;
use App\Models\ProductionWork;
use App\Models\Role;
use App\Models\TableOrder;
use App\Models\User;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Auth;
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

    /** Explicit fixture for tests whose subject requires a configured restaurant. */
    protected function productionAdminOf(?Company $company = null): User
    {
        $admin = $this->adminOf($company);
        if (! $admin->company->default_production_sector_id) {
            $sector = ProductionSector::factory()->create(['company_id' => $admin->company_id]);
            $admin->company->forceFill(['default_production_sector_id' => $sector->id])->save();
        }

        return $admin;
    }

    /** Complete real operational steps before testing an unrelated financial operation. */
    protected function deliverProduction(TableOrder $order): void
    {
        $previous = Auth::user();
        $operator = $previous?->hasPermission('production.operate') ? $previous : User::where('company_id', $order->company_id)->whereHas('role', fn ($query) => $query->where('is_admin', true))->with('role')->firstOrFail();
        $this->actingAs($operator);
        foreach (ProductionWork::whereIn('table_order_item_id', $order->activeItems()->pluck('id'))->orderBy('id')->get() as $work) {
            if ($work->status === 'waiting') {
                app(TransitionProduction::class)->handle($work, $work->version, 'preparing');
                $work->refresh();
            }
            if ($work->status === 'preparing') {
                app(TransitionProduction::class)->handle($work, $work->version, 'ready');
                $work->refresh();
            }
            if (! $work->delivered_at) {
                app(TransitionProduction::class)->handle($work, $work->version, 'delivered');
            }
        }
        if ($previous) {
            $this->actingAs($previous);
        } else {
            Auth::forgetGuards();
        }
    }
}
