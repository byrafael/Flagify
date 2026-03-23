<?php

declare(strict_types=1);

namespace Flagify\Domain;

final class KeyKind
{
    public const ROOT = 'root';
    public const ADMIN = 'admin';
    public const PROJECT_ADMIN = 'project_admin';
    public const PROJECT_READ = 'project_read';
    public const CLIENT_RUNTIME = 'client_runtime';

    public static function all(): array
    {
        return [
            self::ROOT,
            self::ADMIN,
            self::PROJECT_ADMIN,
            self::PROJECT_READ,
            self::CLIENT_RUNTIME,
        ];
    }
}
