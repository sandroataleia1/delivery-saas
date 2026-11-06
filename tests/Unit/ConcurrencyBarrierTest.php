<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

class ConcurrencyBarrierTest extends TestCase
{
    private string $directory;

    private array $processes = [];

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/delivery-barrier-'.bin2hex(random_bytes(8));
        mkdir($this->directory);
    }

    protected function tearDown(): void
    {
        foreach ($this->processes as $process) {
            $process->stop();
        }
        foreach (glob($this->directory.'/*') as $file) {
            unlink($file);
        }
        rmdir($this->directory);
    }

    private function worker(string $id, float $timeout = 3): Process
    {
        $script = 'require $argv[1]; \Tests\Support\ConcurrencyBarrier::awaitRelease($argv[2], $argv[3], (float) $argv[4]); echo "released";';
        $process = new Process([PHP_BINARY, '-d', 'display_errors=stderr', '-r', $script, dirname(__DIR__, 2).'/vendor/autoload.php', $this->directory, $id, (string) $timeout], timeout: 5);
        $process->start();
        $this->processes[] = $process;

        return $process;
    }

    public function test_all_workers_wait_for_explicit_release(): void
    {
        $first = $this->worker('first');
        $second = $this->worker('second');
        $deadline = microtime(true) + 2;
        while ((! is_file($this->directory.'/ready-first') || ! is_file($this->directory.'/ready-second')) && microtime(true) < $deadline) {
            usleep(10000);
        }
        $this->assertFileExists($this->directory.'/ready-first');
        $this->assertFileExists($this->directory.'/ready-second');
        $this->assertTrue($first->isRunning());
        $this->assertTrue($second->isRunning());
        file_put_contents($this->directory.'/release', 'go');
        foreach ([$first, $second] as $process) {
            $this->assertSame(0, $process->wait());
            $this->assertSame('released', $process->getOutput());
        }
    }

    public function test_missing_release_fails_instead_of_running_serially(): void
    {
        $process = $this->worker('timeout', 0.1);
        $this->assertNotSame(0, $process->wait());
        $this->assertStringNotContainsString('released', $process->getOutput());
        $this->assertStringContainsString('barreira', $process->getErrorOutput());
    }
}
