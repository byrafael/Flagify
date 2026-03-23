<?php

declare(strict_types=1);

namespace Flagify\Tests\Support;

use PDO;

final class TestDatabase
{
    public static function create(): PDO
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $pdo->exec('PRAGMA foreign_keys = ON;');
        $schema = file_get_contents(dirname(__DIR__, 2) . '/database/migrations/001_initial_schema.sql');
        $schema = str_replace('`', '', $schema);
        $pdo->exec($schema);

        return $pdo;
    }
}
