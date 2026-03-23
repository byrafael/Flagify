<?php

declare(strict_types=1);

namespace Flagify\Auth;

use Flagify\Domain\KeyKind;
use Flagify\Repository\ApiKeyRepository;
use Flagify\Repository\ClientRepository;
use Flagify\Repository\ProjectRepository;
use Flagify\Support\ApiError;
use Flagify\Support\Clock;

final class ApiKeyAuthenticator
{
    public function __construct(
        private readonly ApiKeyRepository $apiKeys,
        private readonly ProjectRepository $projects,
        private readonly ClientRepository $clients,
        private readonly Clock $clock,
        private readonly string $bootstrapKey
    ) {
    }

    public function authenticateAuthorizationHeader(string $header): ApiPrincipal
    {
        if ($header === '') {
            throw new ApiError('unauthorized', 'Authentication is required', 401);
        }

        if (!preg_match('/^Bearer\s+(.+)$/i', $header, $matches)) {
            throw new ApiError('unauthorized', 'Invalid authorization header', 401);
        }

        return $this->authenticateToken(trim($matches[1]));
    }

    public function authenticateToken(string $token): ApiPrincipal
    {
        if ($this->bootstrapKey !== '' && hash_equals($this->bootstrapKey, $token)) {
            return new ApiPrincipal(KeyKind::ROOT, ['*']);
        }

        $parts = explode('.', $token, 2);
        if (count($parts) !== 2) {
            throw new ApiError('unauthorized', 'Invalid API key format', 401);
        }

        $record = $this->apiKeys->findByPrefix($parts[0]);
        if ($record === null || !password_verify($token, $record['secret_hash'])) {
            throw new ApiError('unauthorized', 'Invalid API key', 401);
        }

        if ($record['revoked_at'] !== null) {
            throw new ApiError('unauthorized', 'API key has been revoked', 401);
        }

        if ($record['expires_at'] !== null && strtotime($record['expires_at']) < time()) {
            throw new ApiError('unauthorized', 'API key has expired', 401);
        }

        if ($record['project_id'] !== null && !$this->projects->isActive($record['project_id'])) {
            throw new ApiError('unauthorized', 'Associated project is inactive', 401);
        }

        if ($record['client_id'] !== null && !$this->clients->isResolvable($record['client_id'])) {
            throw new ApiError('unauthorized', 'Associated client is inactive', 401);
        }

        $this->apiKeys->touchLastUsedAt($record['id'], $this->clock->now());

        return new ApiPrincipal(
            $record['kind'],
            $record['scopes'],
            $record['project_id'],
            $record['client_id'],
            $record['id'],
            $record['name']
        );
    }
}
