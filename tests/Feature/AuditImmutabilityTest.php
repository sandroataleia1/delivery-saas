<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AuditImmutabilityTest extends TestCase
{
    use RefreshDatabase;

    public static function forbiddenOperations(): array
    {
        return [['UPDATE audit_logs SET description = ? WHERE id = ?', ['alterado']], ['DELETE FROM audit_logs WHERE id = ?', []]];
    }

    #[DataProvider('forbiddenOperations')]
    public function test_database_rejects_mutation_and_preserves_history(string $sql, array $bindings): void
    {
        if (DB::getDriverName() === 'sqlite') {
            $this->markTestSkipped('Triggers de imutabilidade existem somente em MariaDB/MySQL.');
        }
        $entry = AuditLog::forceCreateQuietly(['event' => 'test', 'description' => 'original']);
        try {
            DB::statement($sql, [...$bindings, $entry->id]);
            $this->fail('Mutação de auditoria foi permitida pelo banco.');
        } catch (QueryException $exception) {
            $this->assertSame('45000', $exception->errorInfo[0]);
        }
        $this->assertDatabaseHas('audit_logs', ['id' => $entry->id, 'description' => 'original']);
    }
}
