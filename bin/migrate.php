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

$pdo->exec(
    'CREATE TABLE IF NOT EXISTS schema_migrations (
        filename VARCHAR(255) PRIMARY KEY,
        applied_at DATETIME(6) NOT NULL
    )'
);

$applied = [];
$stmt = $pdo->query('SELECT filename FROM schema_migrations');
if ($stmt !== false) {
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $filename) {
        if (is_string($filename)) {
            $applied[$filename] = true;
        }
    }
}

foreach ($files as $file) {
    $filename = basename($file);
    if (isset($applied[$filename])) {
        fwrite(STDOUT, sprintf("Skipped %s (already applied)\n", $filename));
        continue;
    }

    $sql = file_get_contents($file);
    if ($sql === false) {
        throw new RuntimeException(sprintf('Failed to read migration file %s', $file));
    }

    $pdo->exec($sql);
    $record = $pdo->prepare('INSERT INTO schema_migrations (filename, applied_at) VALUES (:filename, UTC_TIMESTAMP(6))');
    $record->execute(['filename' => $filename]);
    fwrite(STDOUT, sprintf("Applied %s\n", $filename));
}
