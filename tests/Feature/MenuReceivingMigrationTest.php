<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class MenuReceivingMigrationTest extends TestCase
{
    public function test_upgrade_backfills_context_and_permissions_and_refuses_destructive_rollback_with_receipts(): void
    {
        $previous = DB::getDefaultConnection();
        config(['database.connections.phase3_upgrade' => ['driver' => 'sqlite', 'database' => ':memory:', 'foreign_key_constraints' => true]]);
        DB::setDefaultConnection('phase3_upgrade');
        try {
            foreach (['companies', 'dining_tables', 'roles', 'table_orders', 'products', 'table_order_items'] as $table) {
                Schema::create($table, function (Blueprint $blueprint) use ($table) {
                    $blueprint->id();
                    if ($table === 'roles') {
                        $blueprint->json('permissions');
                    }
                });
            }
            DB::table('companies')->insert(['id' => 1]);
            DB::table('dining_tables')->insert(['id' => 1]);
            DB::table('table_orders')->insert(['id' => 1]);
            DB::table('roles')->insert([['id' => 1, 'permissions' => json_encode(['tables.operate'])], ['id' => 2, 'permissions' => json_encode(['products.view'])]]);
            $migration = require database_path('migrations/2026_11_07_100005_create_menu_order_receiving_tables.php');
            $migration->up();
            $this->assertSame(64, strlen(DB::table('dining_tables')->value('service_context')));
            $this->assertSame(['tables.operate', 'tables.confirm'], json_decode(DB::table('roles')->where('id', 1)->value('permissions'), true));
            $this->assertSame(['products.view'], json_decode(DB::table('roles')->where('id', 2)->value('permissions'), true));
            DB::table('menu_order_receipts')->insert(['company_id' => 1, 'dining_table_id' => 1, 'table_order_id' => 1, 'service_context' => str_repeat('a', 64), 'client_uuid' => (string) Str::uuid(), 'content_hash' => str_repeat('b', 64), 'tracking_key_hash' => str_repeat('c', 64), 'units' => 1, 'total' => '10.00', 'policy_snapshot' => '{}']);
            try {
                $migration->down();
                $this->fail('Rollback com recebimentos precisa ser recusado.');
            } catch (\RuntimeException $exception) {
                $this->assertStringContainsString('Rollback recusado', $exception->getMessage());
            }
            $this->assertSame(1, DB::table('menu_order_receipts')->count());
            $this->assertTrue(Schema::hasColumn('table_order_items', 'menu_order_receipt_item_id'));
        } finally {
            DB::setDefaultConnection($previous);
            DB::purge('phase3_upgrade');
        }
    }
}
