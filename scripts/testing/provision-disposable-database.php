<?php

use Tests\Support\DisposableDatabase;

require dirname(__DIR__, 2).'/vendor/autoload.php';

$marker = json_decode(file_get_contents(getenv('DISPOSABLE_DB_MARKER')), true, flags: JSON_THROW_ON_ERROR);
$target = [
    'environment' => getenv('APP_ENV'), 'cached' => is_file(dirname(__DIR__, 2).'/bootstrap/cache/config.php'),
    'driver' => getenv('DB_CONNECTION'), 'host' => getenv('DB_HOST'), 'port' => getenv('DB_PORT'),
    'database' => getenv('DB_DATABASE'), 'url' => getenv('DB_URL'),
    'marker' => $marker, 'token' => getenv('DISPOSABLE_DB_TOKEN'),
];
DisposableDatabase::assertSafe($target);
$pdo = new PDO('mysql:host=127.0.0.1;port='.(int) $target['port'], 'root', getenv('DISPOSABLE_ADMIN_PASSWORD'), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$directory = $pdo->query('SELECT @@datadir')->fetchColumn();
DisposableDatabase::assertSafe([...$target, 'actual_directory' => $directory]);
$database = $target['database'];
$pdo->exec("CREATE DATABASE `$database` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
$password = $pdo->quote(getenv('DB_PASSWORD'));
foreach (['127.0.0.1', 'localhost'] as $host) {
    $pdo->exec("CREATE USER 'delivery_test'@'$host' IDENTIFIED BY $password");
    $pdo->exec("GRANT ALL PRIVILEGES ON `$database`.* TO 'delivery_test'@'$host'");
}
$metadata = $pdo->query('SELECT VERSION() AS version, @@default_storage_engine AS engine, @@tx_isolation AS isolation_level')->fetch(PDO::FETCH_ASSOC);
echo json_encode($metadata, JSON_PRETTY_PRINT).PHP_EOL;
