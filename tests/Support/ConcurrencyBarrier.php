<?php

namespace Tests\Support;

use RuntimeException;

final class ConcurrencyBarrier
{
    public static function awaitRelease(string $directory, string $worker, float $timeout = 30): void
    {
        if (! is_dir($directory) || ! preg_match('/^[a-zA-Z0-9_-]+$/D', $worker)) {
            throw new RuntimeException('Diretório ou worker inválido para barreira.');
        }
        file_put_contents($directory.'/ready-'.$worker, 'ready');
        $deadline = microtime(true) + $timeout;
        while (! is_file($directory.'/release')) {
            if (microtime(true) >= $deadline) {
                throw new RuntimeException('Timeout aguardando liberação da barreira.');
            }
            usleep(10000);
        }
    }
}
