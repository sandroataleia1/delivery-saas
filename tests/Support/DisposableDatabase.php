<?php

namespace Tests\Support;

use Illuminate\Foundation\Application;
use RuntimeException;

final class DisposableDatabase
{
    public static function assertSafe(array $target): void
    {
        if ($target['environment'] !== 'testing' || $target['cached'] || ! empty($target['url'])) {
            throw new RuntimeException('Testes recusados: ambiente, cache ou DB_URL inseguro.');
        }

        if ($target['driver'] === 'sqlite' && $target['database'] === ':memory:') {
            return;
        }

        $marker = $target['marker'];
        if (! in_array($target['driver'], ['mariadb', 'mysql'], true)
            || $target['host'] !== '127.0.0.1'
            || (int) $target['port'] < 1024 || (int) $target['port'] > 65535
            || (int) $target['port'] === 3306
            || ! preg_match('/^delivery_test_[a-f0-9]{8,32}$/D', $target['database'])
            || (int) ($marker['port'] ?? 0) !== (int) $target['port']
            || ($marker['database'] ?? null) !== $target['database']
            || ! preg_match('/^[a-f0-9]{32,64}$/D', $target['token'] ?? '')
            || ! hash_equals($marker['token'] ?? '', $target['token'] ?? '')
            || empty($marker['directory'])) {
            throw new RuntimeException('Testes recusados: banco não identificado como descartável.');
        }

        if (isset($target['actual_directory'])
            && self::directory($target['actual_directory']) !== self::directory($marker['directory'])) {
            throw new RuntimeException('Testes recusados: diretório do servidor não corresponde ao marcador.');
        }
    }

    public static function guardApplication(Application $app): void
    {
        $config = $app->make('config');
        $driver = $config->get('database.default');
        $connection = $config->get("database.connections.$driver", []);
        $markerPath = getenv('DISPOSABLE_DB_MARKER');
        $marker = $markerPath && is_file($markerPath)
            ? json_decode(file_get_contents($markerPath), true, flags: JSON_THROW_ON_ERROR) : [];
        $target = [
            'environment' => $app->environment(), 'cached' => $app->configurationIsCached(),
            'driver' => $driver, 'host' => $connection['host'] ?? '', 'port' => $connection['port'] ?? 0,
            'database' => $connection['database'] ?? '', 'url' => $connection['url'] ?? '',
            'marker' => $marker, 'token' => getenv('DISPOSABLE_DB_TOKEN') ?: '',
        ];
        self::assertSafe($target);

        if ($driver !== 'sqlite') {
            $identity = $app->make('db')->connection()->selectOne('SELECT @@datadir AS directory');
            self::assertSafe([...$target, 'actual_directory' => $identity->directory]);
        }
    }

    private static function directory(string $directory): string
    {
        $normalized = rtrim(str_replace('\\', '/', $directory), '/');

        return PHP_OS_FAMILY === 'Windows' ? strtolower($normalized) : $normalized;
    }
}
