<?php

declare(strict_types=1);

namespace Flagify\Auth;

use Flagify\Domain\KeyKind;
use Flagify\Support\ApiError;

final class ScopeAuthorizer
{
    public const PROJECTS_READ = 'projects:read';
    public const PROJECTS_WRITE = 'projects:write';
    public const FLAGS_READ = 'flags:read';
    public const FLAGS_WRITE = 'flags:write';
    public const CLIENTS_READ = 'clients:read';
    public const CLIENTS_WRITE = 'clients:write';
    public const OVERRIDES_READ = 'overrides:read';
    public const OVERRIDES_WRITE = 'overrides:write';
    public const KEYS_READ = 'keys:read';
    public const KEYS_WRITE = 'keys:write';
    public const RUNTIME_READ = 'runtime:read';
    public const RUNTIME_READ_ANY = 'runtime:read_any';

    public function allowedScopesForKind(string $kind): array
    {
        return match ($kind) {
            KeyKind::PROJECT_ADMIN => [
                self::PROJECTS_READ,
                self::PROJECTS_WRITE,
                self::FLAGS_READ,
                self::FLAGS_WRITE,
                self::CLIENTS_READ,
                self::CLIENTS_WRITE,
                self::OVERRIDES_READ,
                self::OVERRIDES_WRITE,
                self::KEYS_READ,
                self::KEYS_WRITE,
                self::RUNTIME_READ_ANY,
            ],
            KeyKind::PROJECT_READ => [
                self::PROJECTS_READ,
                self::FLAGS_READ,
                self::CLIENTS_READ,
                self::OVERRIDES_READ,
                self::RUNTIME_READ_ANY,
            ],
            KeyKind::CLIENT_RUNTIME => [
                self::RUNTIME_READ,
            ],
            default => [],
        };
    }

    public function defaultScopesForKind(string $kind): array
    {
        return $this->allowedScopesForKind($kind);
    }

    public function assertScopesForKind(string $kind, array $scopes): void
    {
        $allowed = $this->allowedScopesForKind($kind);
        if ($allowed === []) {
            throw new ApiError('unsupported_operation', 'Unsupported key kind', 422);
        }

        foreach ($scopes as $scope) {
            if (!in_array($scope, $allowed, true)) {
                throw new ApiError('validation_failed', sprintf('Invalid scope "%s" for key kind "%s"', $scope, $kind), 422);
            }
        }
    }

    public function requireScope(?ApiPrincipal $principal, string $scope, ?string $projectId = null, ?string $clientId = null): ApiPrincipal
    {
        if ($principal === null) {
            throw new ApiError('unauthorized', 'Authentication is required', 401);
        }

        if ($principal->isRoot()) {
            return $principal;
        }

        if (!in_array($scope, $principal->scopes, true)) {
            throw new ApiError('forbidden', 'Missing required scope', 403);
        }

        if ($projectId !== null && $principal->projectId !== null && $principal->projectId !== $projectId) {
            throw new ApiError('forbidden', 'API key cannot access this project', 403);
        }

        if ($clientId !== null && $principal->clientId !== null && $principal->clientId !== $clientId) {
            throw new ApiError('forbidden', 'API key cannot access this client', 403);
        }

        return $principal;
    }
}
