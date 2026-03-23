<?php

declare(strict_types=1);

use Flagify\Support\Database;
use Flagify\Support\Env;

require dirname(__DIR__) . '/autoload.php';

$root = dirname(__DIR__);
Env::load($root . '/.env');

$pdo = Database::connect([
    'driver' => $_ENV['DB_DRIVER'] ?? 'mysql',
    'host' => $_ENV['DB_HOST'] ?? '127.0.0.1',
    'port' => $_ENV['DB_PORT'] ?? '3306',
    'database' => $_ENV['DB_DATABASE'] ?? 'flagify',
    'username' => $_ENV['DB_USERNAME'] ?? 'root',
    'password' => $_ENV['DB_PASSWORD'] ?? '',
]);

$files = glob($root . '/database/migrations/*.sql');
sort($files);

foreach ($files as $file) {
    $sql = file_get_contents($file);
    if ($sql === false) {
        throw new RuntimeException(sprintf('Failed to read migration file %s', $file));
    }

    $pdo->exec($sql);
    fwrite(STDOUT, sprintf("Applied %s\n", basename($file)));
}
