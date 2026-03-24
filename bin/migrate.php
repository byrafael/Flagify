<?php

declare(strict_types=1);

use Flagify\Support\Database;
use Flagify\Support\Env;

require dirname(__DIR__) . '/autoload.php';

$root = dirname(__DIR__);
Env::load($root . '/.env');

assertMigrationAuthorization();

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

if ($applied === []) {
    $legacyApplied = inferAppliedMigrations($pdo, $files);
    if ($legacyApplied !== []) {
        $record = $pdo->prepare('INSERT INTO schema_migrations (filename, applied_at) VALUES (:filename, UTC_TIMESTAMP(6))');
        foreach ($legacyApplied as $filename) {
            $record->execute(['filename' => $filename]);
            $applied[$filename] = true;
            writeOutput(sprintf("Bootstrapped %s from existing schema\n", $filename));
        }
    }
}

foreach ($files as $file) {
    $filename = basename($file);
    if (isset($applied[$filename])) {
        writeOutput(sprintf("Skipped %s (already applied)\n", $filename));
        continue;
    }

    $sql = file_get_contents($file);
    if ($sql === false) {
        throw new RuntimeException(sprintf('Failed to read migration file %s', $file));
    }

    $pdo->exec($sql);
    $record = $pdo->prepare('INSERT INTO schema_migrations (filename, applied_at) VALUES (:filename, UTC_TIMESTAMP(6))');
    $record->execute(['filename' => $filename]);
    writeOutput(sprintf("Applied %s\n", $filename));
}

function inferAppliedMigrations(PDO $pdo, array $files): array
{
    $applied = [];

    foreach ($files as $file) {
        $filename = basename($file);
        $isApplied = match ($filename) {
            '001_initial_schema.sql' => tableExists($pdo, 'projects')
                && tableExists($pdo, 'flags')
                && tableExists($pdo, 'clients')
                && tableExists($pdo, 'flag_overrides')
                && tableExists($pdo, 'api_keys'),
            '002_feature_platform.sql' => tableExists($pdo, 'environments')
                && tableExists($pdo, 'segments')
                && tableExists($pdo, 'flag_environment_configs')
                && tableExists($pdo, 'evaluation_events')
                && columnExists($pdo, 'flags', 'flag_kind')
                && columnExists($pdo, 'flags', 'variants')
                && columnExists($pdo, 'flags', 'default_variant_key')
                && columnExists($pdo, 'flags', 'expires_at')
                && columnExists($pdo, 'flags', 'last_evaluated_at')
                && columnExists($pdo, 'flags', 'stale_status')
                && columnExists($pdo, 'flags', 'prerequisites'),
            '003_platform_expansion.sql' => tableExists($pdo, 'identities')
                && tableExists($pdo, 'identity_traits')
                && tableExists($pdo, 'audit_logs')
                && tableExists($pdo, 'change_requests')
                && tableExists($pdo, 'code_references')
                && columnExists($pdo, 'environments', 'requires_change_requests')
                && columnExists($pdo, 'evaluation_events', 'identity_id')
                && columnExists($pdo, 'evaluation_events', 'identity_kind')
                && columnExists($pdo, 'evaluation_events', 'identity_identifier')
                && columnExists($pdo, 'evaluation_events', 'traits')
                && columnExists($pdo, 'evaluation_events', 'transient_traits'),
            default => false,
        };

        if ($isApplied) {
            $applied[] = $filename;
        }
    }

    return $applied;
}

function tableExists(PDO $pdo, string $table): bool
{
    $stmt = $pdo->prepare(
        'SELECT COUNT(*)
         FROM information_schema.tables
         WHERE table_schema = DATABASE() AND table_name = :table_name'
    );
    $stmt->execute(['table_name' => $table]);

    return (int) $stmt->fetchColumn() === 1;
}

function columnExists(PDO $pdo, string $table, string $column): bool
{
    $stmt = $pdo->prepare(
        'SELECT COUNT(*)
         FROM information_schema.columns
         WHERE table_schema = DATABASE()
           AND table_name = :table_name
           AND column_name = :column_name'
    );
    $stmt->execute([
        'table_name' => $table,
        'column_name' => $column,
    ]);

    return (int) $stmt->fetchColumn() === 1;
}

function writeOutput(string $message): void
{
    if (defined('STDOUT')) {
        fwrite(STDOUT, $message);

        return;
    }

    if (!headers_sent()) {
        header('Content-Type: text/plain; charset=utf-8');
    }

    echo $message;
}

function assertMigrationAuthorization(): void
{
    if (PHP_SAPI === 'cli') {
        return;
    }

    $configuredToken = trim((string) ($_ENV['FLAGIFY_MIGRATE_TOKEN'] ?? ($_ENV['FLAGIFY_BOOTSTRAP_KEY'] ?? '')));
    if ($configuredToken === '') {
        respondAndExit(403, "Migration endpoint is disabled: set FLAGIFY_MIGRATE_TOKEN or FLAGIFY_BOOTSTRAP_KEY.\n");
    }

    $providedToken = requestToken();
    if ($providedToken === '' || !hash_equals($configuredToken, $providedToken)) {
        respondAndExit(403, "Forbidden\n");
    }
}

function requestToken(): string
{
    $queryToken = $_GET['token'] ?? null;
    if (is_string($queryToken) && trim($queryToken) !== '') {
        return trim($queryToken);
    }

    $headerToken = $_SERVER['HTTP_X_MIGRATE_TOKEN'] ?? null;
    if (is_string($headerToken) && trim($headerToken) !== '') {
        return trim($headerToken);
    }

    $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['Authorization'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? null;
    if (!is_string($authHeader) || trim($authHeader) === '') {
        return '';
    }

    if (preg_match('/^Bearer\s+(.+)$/i', trim($authHeader), $matches) === 1) {
        return trim($matches[1]);
    }

    return '';
}

function respondAndExit(int $status, string $message): never
{
    http_response_code($status);
    if (!headers_sent()) {
        header('Content-Type: text/plain; charset=utf-8');
    }
    echo $message;
    exit;
}
