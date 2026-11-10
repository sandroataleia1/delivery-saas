<?php

namespace Tests\Feature;

use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DemoHistorySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class SeederSafetyTest extends TestCase
{
    use RefreshDatabase;

    public static function demonstrationSeeders(): array
    {
        return [[DatabaseSeeder::class], [DemoHistorySeeder::class]];
    }

    private function counts(): array
    {
        $counts = [];
        foreach (DB::getSchemaBuilder()->getTableListing() as $table) {
            $counts[$table] = DB::table($table)->count();
        }

        return $counts;
    }

    #[DataProvider('demonstrationSeeders')]
    public function test_production_refuses_direct_seeder_before_any_write(string $seeder): void
    {
        $before = $this->counts();
        $this->app->instance('env', 'production');
        try {
            (new $seeder)->run();
            $this->fail('Seeder de demonstração foi permitido em produção.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('produção', $exception->getMessage());
        } finally {
            $this->app->instance('env', 'testing');
            $this->assertSame($before, $this->counts());
        }
    }

    #[DataProvider('demonstrationSeeders')]
    public function test_force_does_not_bypass_production_guard(string $seeder): void
    {
        $before = $this->counts();
        $this->app->instance('env', 'production');
        try {
            $this->artisan('db:seed', ['--class' => $seeder, '--force' => true]);
            $this->fail('db:seed --force não deveria executar em produção.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('produção', $exception->getMessage());
        } finally {
            $this->app->instance('env', 'testing');
            $this->assertSame($before, $this->counts());
        }
    }

    public function test_demonstration_seeder_still_works_in_testing(): void
    {
        $this->seed(DatabaseSeeder::class);
        $this->assertDatabaseHas('users', ['email' => 'super@delivery.test', 'is_super_admin' => true]);
        $this->assertDatabaseHas('companies', ['trade_name' => 'Pizzaria Demo']);
        $this->assertDatabaseCount('dining_tables', 8);
        $this->assertDatabaseCount('sales', 0);
        $this->assertDatabaseCount('cash_movements', 0);
    }
}
