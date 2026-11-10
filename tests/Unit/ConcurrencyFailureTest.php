<?php

namespace Tests\Unit;

use Illuminate\Database\DeadlockException;
use PDOException;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tests\Support\ConcurrencyFailure;

class ConcurrencyFailureTest extends TestCase
{
    public function test_nested_deadlock_keeps_the_driver_code(): void
    {
        $driver = new PDOException('deadlock');
        $driver->errorInfo = ['40001', 1213, 'deadlock'];
        $exception = new DeadlockException('nested transaction', 0, $driver);
        $this->assertSame(1213, ConcurrencyFailure::driverCode($exception));
    }

    public function test_message_containing_1213_is_not_treated_as_a_database_deadlock(): void
    {
        $this->assertNull(ConcurrencyFailure::driverCode(new RuntimeException('Unexpected failure 1213')));
    }
}
