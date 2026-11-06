<?php

namespace Tests\Support;

use PDOException;
use Throwable;

final class ConcurrencyFailure
{
    public static function driverCode(Throwable $exception): ?int
    {
        do {
            if ($exception instanceof PDOException && isset($exception->errorInfo[1])) {
                return (int) $exception->errorInfo[1];
            }
        } while ($exception = $exception->getPrevious());

        return null;
    }
}
