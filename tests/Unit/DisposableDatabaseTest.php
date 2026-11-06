<?php

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tests\Support\DisposableDatabase;

class DisposableDatabaseTest extends TestCase
{
    public static function unsafeConfigurations(): array
    {
        return [
            'production' => ['environment', 'production'],
            'cached configuration' => ['cached', true],
            'operational port' => ['port', 3306],
            'remote host' => ['host', 'db.example.com'],
            'operational schema' => ['database', 'delivery'],
            'URL override' => ['url', 'mysql://localhost/real'],
            'socket override' => ['socket', '/var/run/operational-mysql.sock'],
            'missing marker' => ['marker', []],
            'different port' => ['marker', ['port' => 34002]],
            'different instance' => ['actual_directory', '/real/mysql'],
            'persistent sqlite' => ['driver', 'sqlite'],
        ];
    }

    private function configuration(): array
    {
        return [
            'environment' => 'testing', 'cached' => false, 'driver' => 'mariadb',
            'host' => '127.0.0.1', 'port' => 34001, 'database' => 'delivery_test_ab12cd34',
            'url' => '', 'socket' => '', 'actual_directory' => '/tmp/disposable/data/',
            'marker' => [
                'port' => 34001, 'database' => 'delivery_test_ab12cd34',
                'directory' => '/tmp/disposable/data', 'token' => str_repeat('a', 32),
            ],
            'token' => str_repeat('a', 32),
        ];
    }

    #[DataProvider('unsafeConfigurations')]
    public function test_rejects_unsafe_targets_without_opening_a_connection(string $key, mixed $value): void
    {
        $configuration = $this->configuration();
        $configuration[$key] = $value;
        $this->expectException(RuntimeException::class);
        DisposableDatabase::assertSafe($configuration);
    }

    public function test_accepts_only_identified_disposable_instance(): void
    {
        DisposableDatabase::assertSafe($this->configuration());
        $configuration = $this->configuration();
        $configuration['token'] = str_repeat('b', 32);
        $this->expectException(RuntimeException::class);
        DisposableDatabase::assertSafe($configuration);
    }

    public function test_in_memory_sqlite_is_allowed_but_production_is_not(): void
    {
        $configuration = $this->configuration();
        $configuration['driver'] = 'sqlite';
        $configuration['database'] = ':memory:';
        DisposableDatabase::assertSafe($configuration);
        $configuration['environment'] = 'production';
        $this->expectException(RuntimeException::class);
        DisposableDatabase::assertSafe($configuration);
    }
}
