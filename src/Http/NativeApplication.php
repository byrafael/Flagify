<?php

declare(strict_types=1);

namespace Flagify\Http;

use Flagify\Auth\ApiKeyAuthenticator;
use Flagify\Auth\ApiKeyGenerator;
use Flagify\Auth\ApiPrincipal;
use Flagify\Auth\ScopeAuthorizer;
use Flagify\Domain\KeyKind;
use Flagify\Repository\ApiKeyRepository;
use Flagify\Repository\ClientRepository;
use Flagify\Repository\FlagRepository;
use Flagify\Repository\OverrideRepository;
use Flagify\Repository\ProjectRepository;
use Flagify\Service\FlagValueValidator;
use Flagify\Service\ResolvedConfigService;
use Flagify\Support\ApiError;
use Flagify\Support\ApiResponse;
use Flagify\Support\Json;
use PDOException;
use Throwable;

final class NativeApplication
{
    public function __construct(
        private readonly ApiKeyAuthenticator $authenticator,
        private readonly ApiKeyGenerator $keyGenerator,
        private readonly ScopeAuthorizer $authorizer,
        private readonly ProjectRepository $projects,
        private readonly FlagRepository $flags,
        private readonly ClientRepository $clients,
        private readonly OverrideRepository $overrides,
        private readonly ApiKeyRepository $apiKeys,
        private readonly FlagValueValidator $validator,
        private readonly ResolvedConfigService $resolvedConfig
    ) {
    }

    public function run(): void
    {
        try {
            $response = $this->dispatch();
        } catch (ApiError $error) {
            $response = new ApiResponse($error->status(), [
                'error' => array_filter([
                    'code' => $error->codeName(),
                    'message' => $error->getMessage(),
                    'details' => $error->details() === [] ? null : $error->details(),
                ], static fn(mixed $value): bool => $value !== null),
            ]);
        } catch (PDOException $error) {
            $message = strtolower($error->getMessage());
            $conflict = str_contains($message, 'duplicate entry') || str_contains($message, 'unique constraint failed');
            $response = new ApiResponse($conflict ? 409 : 500, [
                'error' => [
                    'code' => $conflict ? 'conflict' : 'internal_error',
                    'message' => $conflict ? 'Resource conflict' : 'Internal server error',
                ],
            ]);
        } catch (Throwable) {
            $response = new ApiResponse(500, [
                'error' => [
                    'code' => 'internal_error',
                    'message' => 'Internal server error',
                ],
            ]);
        }

        $response->emit();
    }

    private function dispatch(): ApiResponse
    {
        $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

        if ($method === 'OPTIONS') {
            return new ApiResponse(204, null);
        }

        $principal = $this->authenticator->authenticateAuthorizationHeader($this->authorizationHeader());

        if ($method === 'POST' && $path === '/api/v1/projects') {
            return $this->createProject($principal);
        }
        if ($method === 'GET' && $path === '/api/v1/projects') {
            return $this->listProjects($principal);
        }
        if (preg_match('#^/api/v1/projects/([^/]+)$#', $path, $m) === 1) {
            return match ($method) {
                'GET' => $this->showProject($principal, $m[1]),
                'PATCH' => $this->updateProject($principal, $m[1]),
                'DELETE' => $this->deleteProject($principal, $m[1]),
                default => throw new ApiError('not_found', 'Route not found', 404),
            };
        }
        if (preg_match('#^/api/v1/projects/([^/]+)/flags$#', $path, $m) === 1) {
            return match ($method) {
                'POST' => $this->createFlag($principal, $m[1]),
                'GET' => $this->listFlags($principal, $m[1]),
                default => throw new ApiError('not_found', 'Route not found', 404),
            };
        }
        if (preg_match('#^/api/v1/projects/([^/]+)/flags/([^/]+)$#', $path, $m) === 1) {
            return match ($method) {
                'GET' => $this->showFlag($principal, $m[1], $m[2]),
                'PATCH' => $this->updateFlag($principal, $m[1], $m[2]),
                'DELETE' => $this->deleteFlag($principal, $m[1], $m[2]),
                default => throw new ApiError('not_found', 'Route not found', 404),
            };
        }
        if (preg_match('#^/api/v1/projects/([^/]+)/clients$#', $path, $m) === 1) {
            return match ($method) {
                'POST' => $this->createClient($principal, $m[1]),
                'GET' => $this->listClients($principal, $m[1]),
                default => throw new ApiError('not_found', 'Route not found', 404),
            };
        }
        if (preg_match('#^/api/v1/projects/([^/]+)/clients/([^/]+)$#', $path, $m) === 1) {
            return match ($method) {
                'GET' => $this->showClient($principal, $m[1], $m[2]),
                'PATCH' => $this->updateClient($principal, $m[1], $m[2]),
                'DELETE' => $this->deleteClient($principal, $m[1], $m[2]),
                default => throw new ApiError('not_found', 'Route not found', 404),
            };
        }
        if (preg_match('#^/api/v1/projects/([^/]+)/clients/([^/]+)/flags/([^/]+)/override$#', $path, $m) === 1) {
            return match ($method) {
                'PUT' => $this->putOverride($principal, $m[1], $m[2], $m[3]),
                'DELETE' => $this->deleteOverride($principal, $m[1], $m[2], $m[3]),
                default => throw new ApiError('not_found', 'Route not found', 404),
            };
        }
        if (preg_match('#^/api/v1/projects/([^/]+)/clients/([^/]+)/overrides$#', $path, $m) === 1 && $method === 'GET') {
            return $this->listOverrides($principal, $m[1], $m[2]);
        }
        if ($method === 'POST' && $path === '/api/v1/keys') {
            return $this->createKey($principal);
        }
        if ($method === 'GET' && $path === '/api/v1/keys') {
            return $this->listKeys($principal);
        }
        if (preg_match('#^/api/v1/keys/([^/]+)$#', $path, $m) === 1 && $method === 'GET') {
            return $this->showKey($principal, $m[1]);
        }
        if (preg_match('#^/api/v1/keys/([^/]+)/revoke$#', $path, $m) === 1 && $method === 'POST') {
            return $this->revokeKey($principal, $m[1]);
        }
        if ($method === 'GET' && $path === '/api/v1/runtime/config') {
            return $this->currentRuntimeConfig($principal);
        }
        if (preg_match('#^/api/v1/runtime/config/([^/]+)$#', $path, $m) === 1 && $method === 'GET') {
            return $this->currentRuntimeFlag($principal, $m[1]);
        }
        if (preg_match('#^/api/v1/runtime/projects/([^/]+)/clients/([^/]+)/config$#', $path, $m) === 1 && $method === 'GET') {
            return $this->projectClientConfig($principal, $m[1], $m[2]);
        }
        if (preg_match('#^/api/v1/runtime/projects/([^/]+)/clients/([^/]+)/config/([^/]+)$#', $path, $m) === 1 && $method === 'GET') {
            return $this->projectClientFlag($principal, $m[1], $m[2], $m[3]);
        }

        throw new ApiError('not_found', 'Route not found', 404);
    }

    private function createProject(ApiPrincipal $principal): ApiResponse
    {
        $this->requireRoot($principal);
        $payload = $this->body();
        $project = $this->projects->create([
            'name' => $this->requireString($payload, 'name'),
            'slug' => $this->requireSlug($payload, 'slug'),
            'description' => $this->nullableString($payload['description'] ?? null),
        ]);

        return new ApiResponse(201, $this->normalize($project));
    }

    private function listProjects(ApiPrincipal $principal): ApiResponse
    {
        $this->authorizer->requireScope($principal, ScopeAuthorizer::PROJECTS_READ, $principal->projectId);
        $limit = $this->limit();
        $offset = $this->offset();

        if (!$principal->isRoot() && $principal->projectId !== null) {
            return new ApiResponse(200, [
                'items' => [$this->normalize($this->requireProject($principal->projectId))],
                'meta' => ['total' => 1, 'limit' => $limit, 'offset' => $offset],
            ]);
        }

        return new ApiResponse(200, [
            'items' => array_map($this->normalize(...), $this->projects->paginate($limit, $offset)),
            'meta' => ['total' => $this->projects->count(), 'limit' => $limit, 'offset' => $offset],
        ]);
    }

    private function showProject(ApiPrincipal $principal, string $projectId): ApiResponse
    {
        $this->authorizer->requireScope($principal, ScopeAuthorizer::PROJECTS_READ, $projectId);
        $project = $this->requireProject($projectId);
        if ($project['status'] === 'deleted') {
            throw new ApiError('resource_deleted', 'Project has been deleted', 410);
        }

        return new ApiResponse(200, $this->normalize($project));
    }

    private function updateProject(ApiPrincipal $principal, string $projectId): ApiResponse
    {
        $this->requireRoot($principal);
        $project = $this->requireProject($projectId);
        if ($project['status'] === 'deleted') {
            throw new ApiError('resource_deleted', 'Project has been deleted', 410);
        }

        $payload = $this->body();
        $updates = [];
        if (array_key_exists('name', $payload)) {
            $updates['name'] = $this->requireString($payload, 'name');
        }
        if (array_key_exists('slug', $payload)) {
            $updates['slug'] = $this->requireSlug($payload, 'slug');
        }
        if (array_key_exists('description', $payload)) {
            $updates['description'] = $this->nullableString($payload['description']);
        }

        return new ApiResponse(200, $this->normalize($this->projects->update($projectId, $updates)));
    }

    private function deleteProject(ApiPrincipal $principal, string $projectId): ApiResponse
    {
        $this->requireRoot($principal);
        $this->requireProject($projectId);
        $this->projects->softDelete($projectId);

        return new ApiResponse(204, null);
    }

    private function createFlag(ApiPrincipal $principal, string $projectId): ApiResponse
    {
        $this->authorizer->requireScope($principal, ScopeAuthorizer::FLAGS_WRITE, $projectId);
        $this->assertProjectActive($projectId);
        $payload = $this->body();

        foreach (['key', 'name', 'type', 'default_value'] as $field) {
            if (!array_key_exists($field, $payload)) {
                throw new ApiError('validation_failed', sprintf('%s is required', $field), 422);
            }
        }

        $this->assertResourceKey($this->requireString($payload, 'key'), 'key');
        $this->validator->validateFlag($payload['type'], $payload['default_value'], $payload['options'] ?? null);

        return new ApiResponse(201, $this->normalize($this->flags->create([
            'project_id' => $projectId,
            'key' => $payload['key'],
            'name' => $this->requireString($payload, 'name'),
            'description' => $this->nullableString($payload['description'] ?? null),
            'type' => $payload['type'],
            'default_value' => $payload['default_value'],
            'options' => $payload['options'] ?? null,
        ])));
    }

    private function listFlags(ApiPrincipal $principal, string $projectId): ApiResponse
    {
        $this->authorizer->requireScope($principal, ScopeAuthorizer::FLAGS_READ, $projectId);
        $this->assertProjectActive($projectId);
        $limit = $this->limit();
        $offset = $this->offset();
        $includeArchived = $this->boolQuery('include_archived', false);

        return new ApiResponse(200, [
            'items' => array_map($this->normalize(...), $this->flags->paginateByProject($projectId, $limit, $offset, $includeArchived)),
            'meta' => ['total' => $this->flags->countByProject($projectId, $includeArchived), 'limit' => $limit, 'offset' => $offset],
        ]);
    }

    private function showFlag(ApiPrincipal $principal, string $projectId, string $flagId): ApiResponse
    {
        $this->authorizer->requireScope($principal, ScopeAuthorizer::FLAGS_READ, $projectId);
        $this->assertProjectActive($projectId);

        return new ApiResponse(200, $this->normalize($this->requireFlag($projectId, $flagId)));
    }

    private function updateFlag(ApiPrincipal $principal, string $projectId, string $flagId): ApiResponse
    {
        $this->authorizer->requireScope($principal, ScopeAuthorizer::FLAGS_WRITE, $projectId);
        $this->assertProjectActive($projectId);
        $flag = $this->requireFlag($projectId, $flagId);
        $payload = $this->body();

        if (array_key_exists('type', $payload) && $payload['type'] !== $flag['type']) {
            throw new ApiError('unsupported_operation', 'Flag type cannot be changed', 422);
        }
        if (array_key_exists('key', $payload)) {
            $this->assertResourceKey($this->requireString($payload, 'key'), 'key');
        }
        if (array_key_exists('status', $payload) && !in_array($payload['status'], ['active', 'archived'], true)) {
            throw new ApiError('validation_failed', 'status must be active or archived', 422);
        }
        if (array_key_exists('description', $payload)) {
            $payload['description'] = $this->nullableString($payload['description']);
        }

        $this->validator->assertOptionsCompatible(
            $flag['type'],
            $payload['default_value'] ?? $flag['default_value'],
            array_key_exists('options', $payload) ? $payload['options'] : $flag['options'],
            $this->overrides->valuesForFlag($flagId)
        );

        return new ApiResponse(200, $this->normalize($this->flags->update($projectId, $flagId, $payload)));
    }

    private function deleteFlag(ApiPrincipal $principal, string $projectId, string $flagId): ApiResponse
    {
        $this->authorizer->requireScope($principal, ScopeAuthorizer::FLAGS_WRITE, $projectId);
        $this->assertProjectActive($projectId);
        $this->requireFlag($projectId, $flagId);

        if ($this->overrides->hasAnyForFlag($flagId)) {
            $this->flags->update($projectId, $flagId, ['status' => 'archived']);
        } else {
            $this->flags->delete($projectId, $flagId);
        }

        return new ApiResponse(204, null);
    }

    private function createClient(ApiPrincipal $principal, string $projectId): ApiResponse
    {
        $this->authorizer->requireScope($principal, ScopeAuthorizer::CLIENTS_WRITE, $projectId);
        $this->assertProjectActive($projectId);
        $payload = $this->body();

        foreach (['key', 'name'] as $field) {
            if (!array_key_exists($field, $payload)) {
                throw new ApiError('validation_failed', sprintf('%s is required', $field), 422);
            }
        }

        $this->assertResourceKey($this->requireString($payload, 'key'), 'key');

        return new ApiResponse(201, $this->normalize($this->clients->create([
            'project_id' => $projectId,
            'key' => $payload['key'],
            'name' => $this->requireString($payload, 'name'),
            'description' => $this->nullableString($payload['description'] ?? null),
            'metadata' => $this->metadata($payload['metadata'] ?? []),
        ])));
    }

    private function listClients(ApiPrincipal $principal, string $projectId): ApiResponse
    {
        $this->authorizer->requireScope($principal, ScopeAuthorizer::CLIENTS_READ, $projectId);
        $this->assertProjectActive($projectId);
        $limit = $this->limit();
        $offset = $this->offset();

        return new ApiResponse(200, [
            'items' => array_map($this->normalize(...), $this->clients->paginateByProject($projectId, $limit, $offset)),
            'meta' => ['total' => $this->clients->countByProject($projectId), 'limit' => $limit, 'offset' => $offset],
        ]);
    }

    private function showClient(ApiPrincipal $principal, string $projectId, string $clientId): ApiResponse
    {
        $this->authorizer->requireScope($principal, ScopeAuthorizer::CLIENTS_READ, $projectId);
        $this->assertProjectActive($projectId);
        $client = $this->requireClient($projectId, $clientId);
        if ($client['status'] === 'deleted') {
            throw new ApiError('resource_deleted', 'Client has been deleted', 410);
        }

        return new ApiResponse(200, $this->normalize($client));
    }

    private function updateClient(ApiPrincipal $principal, string $projectId, string $clientId): ApiResponse
    {
        $this->authorizer->requireScope($principal, ScopeAuthorizer::CLIENTS_WRITE, $projectId);
        $this->assertProjectActive($projectId);
        $this->requireClient($projectId, $clientId);
        $payload = $this->body();

        if (array_key_exists('key', $payload)) {
            $this->assertResourceKey($this->requireString($payload, 'key'), 'key');
        }
        if (array_key_exists('status', $payload) && !in_array($payload['status'], ['active', 'disabled', 'deleted'], true)) {
            throw new ApiError('validation_failed', 'status must be active, disabled, or deleted', 422);
        }
        if (array_key_exists('description', $payload)) {
            $payload['description'] = $this->nullableString($payload['description']);
        }
        if (array_key_exists('metadata', $payload)) {
            $payload['metadata'] = $this->metadata($payload['metadata']);
        }

        return new ApiResponse(200, $this->normalize($this->clients->update($projectId, $clientId, $payload)));
    }

    private function deleteClient(ApiPrincipal $principal, string $projectId, string $clientId): ApiResponse
    {
        $this->authorizer->requireScope($principal, ScopeAuthorizer::CLIENTS_WRITE, $projectId);
        $this->assertProjectActive($projectId);
        $this->requireClient($projectId, $clientId);
        $this->clients->softDelete($projectId, $clientId);

        return new ApiResponse(204, null);
    }

    private function putOverride(ApiPrincipal $principal, string $projectId, string $clientId, string $flagId): ApiResponse
    {
        $this->authorizer->requireScope($principal, ScopeAuthorizer::OVERRIDES_WRITE, $projectId);
        $this->assertProjectActive($projectId);
        $client = $this->requireClient($projectId, $clientId);
        $flag = $this->requireFlag($projectId, $flagId);
        if ($client['status'] !== 'active') {
            throw new ApiError('unsupported_operation', 'Client is inactive', 409);
        }
        if ($flag['status'] !== 'active') {
            throw new ApiError('unsupported_operation', 'Archived flags cannot receive overrides', 409);
        }

        $payload = $this->body();
        if (!array_key_exists('value', $payload)) {
            throw new ApiError('validation_failed', 'value is required', 422);
        }
        $this->validator->validateValue($flag['type'], $payload['value'], $flag['options']);

        return new ApiResponse(200, $this->normalize($this->overrides->upsert($projectId, $flagId, $clientId, $payload['value'])));
    }

    private function listOverrides(ApiPrincipal $principal, string $projectId, string $clientId): ApiResponse
    {
        $this->authorizer->requireScope($principal, ScopeAuthorizer::OVERRIDES_READ, $projectId);
        $this->assertProjectActive($projectId);
        $client = $this->requireClient($projectId, $clientId);
        if ($client['status'] === 'deleted') {
            throw new ApiError('resource_deleted', 'Client has been deleted', 410);
        }

        return new ApiResponse(200, [
            'items' => array_map($this->normalize(...), $this->overrides->forClient($projectId, $clientId)),
        ]);
    }

    private function deleteOverride(ApiPrincipal $principal, string $projectId, string $clientId, string $flagId): ApiResponse
    {
        $this->authorizer->requireScope($principal, ScopeAuthorizer::OVERRIDES_WRITE, $projectId);
        $this->assertProjectActive($projectId);
        $this->requireClient($projectId, $clientId);
        $this->requireFlag($projectId, $flagId);
        $this->overrides->delete($flagId, $clientId);

        return new ApiResponse(204, null);
    }

    private function createKey(ApiPrincipal $principal): ApiResponse
    {
        $payload = $this->body();
        foreach (['kind', 'name'] as $field) {
            if (!array_key_exists($field, $payload)) {
                throw new ApiError('validation_failed', sprintf('%s is required', $field), 422);
            }
        }

        $kind = $this->requireString($payload, 'kind');
        if (!in_array($kind, [KeyKind::PROJECT_ADMIN, KeyKind::PROJECT_READ, KeyKind::CLIENT_RUNTIME], true)) {
            throw new ApiError('unsupported_operation', 'Only project-scoped and client runtime keys can be created', 422);
        }

        $projectId = isset($payload['project_id']) ? $this->requireString($payload, 'project_id') : $principal->projectId;
        if ($projectId === null) {
            throw new ApiError('validation_failed', 'A valid project_id is required', 422);
        }
        $this->authorizer->requireScope($principal, ScopeAuthorizer::KEYS_WRITE, $projectId);
        if ($this->requireProject($projectId)['status'] !== 'active') {
            throw new ApiError('resource_deleted', 'Project has been deleted', 410);
        }

        $clientId = null;
        if ($kind === KeyKind::CLIENT_RUNTIME) {
            $clientId = $this->requireString($payload, 'client_id');
            if ($this->requireClient($projectId, $clientId)['status'] !== 'active') {
                throw new ApiError('unsupported_operation', 'Client is inactive', 409);
            }
        }

        $scopes = $payload['scopes'] ?? $this->authorizer->defaultScopesForKind($kind);
        if (!is_array($scopes)) {
            throw new ApiError('validation_failed', 'scopes must be an array', 422);
        }
        $this->authorizer->assertScopesForKind($kind, $scopes);

        $generated = $this->keyGenerator->generate();
        $key = $this->apiKeys->create([
            'project_id' => $projectId,
            'client_id' => $clientId,
            'name' => $this->requireString($payload, 'name'),
            'prefix' => $generated['prefix'],
            'secret_hash' => $generated['secret_hash'],
            'kind' => $kind,
            'scopes' => array_values($scopes),
            'expires_at' => $this->nullableString($payload['expires_at'] ?? null),
        ]);

        return new ApiResponse(201, [...$this->normalize($key), 'secret' => $generated['secret']]);
    }

    private function listKeys(ApiPrincipal $principal): ApiResponse
    {
        $projectId = !$principal->isRoot() ? $principal->projectId : ($this->query('project_id') ?: null);
        $this->authorizer->requireScope($principal, ScopeAuthorizer::KEYS_READ, $projectId);
        $limit = $this->limit();
        $offset = $this->offset();

        return new ApiResponse(200, [
            'items' => array_map($this->normalize(...), $this->apiKeys->paginate($limit, $offset, $projectId)),
            'meta' => ['total' => $this->apiKeys->count($projectId), 'limit' => $limit, 'offset' => $offset],
        ]);
    }

    private function showKey(ApiPrincipal $principal, string $keyId): ApiResponse
    {
        $key = $this->requireKey($keyId);
        $this->authorizer->requireScope($principal, ScopeAuthorizer::KEYS_READ, $key['project_id']);
        if (!$principal->isRoot() && $principal->projectId !== $key['project_id']) {
            throw new ApiError('forbidden', 'API key cannot access this key', 403);
        }

        return new ApiResponse(200, $this->normalize($key));
    }

    private function revokeKey(ApiPrincipal $principal, string $keyId): ApiResponse
    {
        $key = $this->requireKey($keyId);
        $this->authorizer->requireScope($principal, ScopeAuthorizer::KEYS_WRITE, $key['project_id']);
        if (!$principal->isRoot() && $principal->projectId !== $key['project_id']) {
            throw new ApiError('forbidden', 'API key cannot revoke this key', 403);
        }
        $this->apiKeys->revoke($keyId);

        return new ApiResponse(204, null);
    }

    private function currentRuntimeConfig(ApiPrincipal $principal): ApiResponse
    {
        $principal = $this->authorizer->requireScope($principal, ScopeAuthorizer::RUNTIME_READ, $principal->projectId, $principal->clientId);
        if ($principal->projectId === null || $principal->clientId === null) {
            throw new ApiError('forbidden', 'This key is not bound to a runtime client', 403);
        }

        return new ApiResponse(200, $this->resolvedPayload($principal->projectId, $principal->clientId));
    }

    private function currentRuntimeFlag(ApiPrincipal $principal, string $flagKey): ApiResponse
    {
        $payload = $this->currentRuntimeConfig($principal)->payload();
        if ($payload === null || !array_key_exists($flagKey, $payload['flags'])) {
            throw new ApiError('not_found', 'Flag not found', 404);
        }

        return new ApiResponse(200, ['key' => $flagKey, 'value' => $payload['flags'][$flagKey]]);
    }

    private function projectClientConfig(ApiPrincipal $principal, string $projectId, string $clientKey): ApiResponse
    {
        $this->authorizer->requireScope($principal, ScopeAuthorizer::RUNTIME_READ_ANY, $projectId);
        $client = $this->clients->findByKey($projectId, $clientKey);
        if ($client === null) {
            throw new ApiError('not_found', 'Project or client not found', 404);
        }

        return new ApiResponse(200, $this->resolvedPayload($projectId, $client['id']));
    }

    private function projectClientFlag(ApiPrincipal $principal, string $projectId, string $clientKey, string $flagKey): ApiResponse
    {
        $payload = $this->projectClientConfig($principal, $projectId, $clientKey)->payload();
        if ($payload === null || !array_key_exists($flagKey, $payload['flags'])) {
            throw new ApiError('not_found', 'Flag not found', 404);
        }

        return new ApiResponse(200, ['key' => $flagKey, 'value' => $payload['flags'][$flagKey]]);
    }

    private function resolvedPayload(string $projectId, string $clientId): array
    {
        $project = $this->requireProject($projectId);
        $client = $this->requireClient($projectId, $clientId);
        if ($project['status'] !== 'active' || $client['status'] !== 'active') {
            throw new ApiError('unauthorized', 'Project or client is inactive', 401);
        }

        $payload = $this->resolvedConfig->resolveProjectClient($projectId, $client);
        $payload['project']['slug'] = $project['slug'];

        return $this->normalize($payload);
    }

    private function requireProject(string $projectId): array
    {
        $project = $this->projects->find($projectId);
        if ($project === null) {
            throw new ApiError('not_found', 'Project not found', 404);
        }

        return $project;
    }

    private function requireFlag(string $projectId, string $flagId): array
    {
        $flag = $this->flags->find($projectId, $flagId);
        if ($flag === null) {
            throw new ApiError('not_found', 'Flag not found', 404);
        }

        return $flag;
    }

    private function requireClient(string $projectId, string $clientId): array
    {
        $client = $this->clients->find($projectId, $clientId);
        if ($client === null) {
            throw new ApiError('not_found', 'Client not found', 404);
        }

        return $client;
    }

    private function requireKey(string $keyId): array
    {
        $key = $this->apiKeys->find($keyId);
        if ($key === null) {
            throw new ApiError('not_found', 'API key not found', 404);
        }

        return $key;
    }

    private function requireRoot(ApiPrincipal $principal): void
    {
        if (!$principal->isRoot()) {
            throw new ApiError('forbidden', 'Only root can perform this action', 403);
        }
    }

    private function assertProjectActive(string $projectId): void
    {
        if ($this->requireProject($projectId)['status'] !== 'active') {
            throw new ApiError('resource_deleted', 'Project has been deleted', 410);
        }
    }

    private function authorizationHeader(): string
    {
        $header = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['Authorization'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
        if ($header === '' && function_exists('getallheaders')) {
            $headers = getallheaders();
            $header = $headers['Authorization'] ?? $headers['authorization'] ?? '';
        }

        return trim($header);
    }

    private function body(): array
    {
        $raw = file_get_contents('php://input');
        if ($raw === false || trim($raw) === '') {
            return [];
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            throw new ApiError('validation_failed', 'Request body must be a JSON object', 422);
        }

        return $decoded;
    }

    private function query(string $key): ?string
    {
        return isset($_GET[$key]) && is_string($_GET[$key]) ? trim($_GET[$key]) : null;
    }

    private function limit(): int
    {
        return $this->intQuery('limit', 50, 1, 200);
    }

    private function offset(): int
    {
        return $this->intQuery('offset', 0, 0, 1_000_000);
    }

    private function intQuery(string $key, int $default, int $min, int $max): int
    {
        $value = isset($_GET[$key]) ? (int) $_GET[$key] : $default;
        if ($value < $min || $value > $max) {
            throw new ApiError('validation_failed', sprintf('%s must be between %d and %d', $key, $min, $max), 422);
        }

        return $value;
    }

    private function boolQuery(string $key, bool $default): bool
    {
        if (!array_key_exists($key, $_GET)) {
            return $default;
        }

        return filter_var($_GET[$key], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? $default;
    }

    private function requireString(array $payload, string $field): string
    {
        $value = $payload[$field] ?? null;
        if (!is_string($value) || trim($value) === '') {
            throw new ApiError('validation_failed', sprintf('%s is required', $field), 422);
        }

        return trim($value);
    }

    private function requireSlug(array $payload, string $field): string
    {
        $value = $this->requireString($payload, $field);
        if (!preg_match('/^[a-z0-9-]+$/', $value)) {
            throw new ApiError('validation_failed', 'slug must contain only lowercase letters, numbers, and dashes', 422);
        }

        return $value;
    }

    private function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        if (!is_string($value)) {
            throw new ApiError('validation_failed', 'Value must be a string', 422);
        }

        $value = trim($value);
        return $value === '' ? null : $value;
    }

    private function assertResourceKey(string $value, string $field): void
    {
        if (!preg_match('/^[a-z0-9_-]+$/', $value)) {
            throw new ApiError('validation_failed', sprintf('%s must contain only lowercase letters, numbers, underscores, and dashes', $field), 422);
        }
    }

    private function metadata(mixed $value): array
    {
        if (!is_array($value)) {
            throw new ApiError('validation_failed', 'metadata must be a JSON object', 422);
        }
        if (strlen(Json::encode($value)) > 16 * 1024) {
            throw new ApiError('validation_failed', 'metadata exceeds the 16KB limit', 422);
        }

        return $value;
    }

    private function normalize(array $payload): array
    {
        foreach ($payload as $key => $value) {
            if (is_array($value)) {
                $payload[$key] = $this->normalize($value);
                continue;
            }

            if (is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}(\.\d+)?$/', $value) === 1) {
                $timestamp = strtotime($value . ' UTC');
                $payload[$key] = $timestamp === false ? $value : gmdate('Y-m-d\TH:i:s\Z', $timestamp);
            }
        }

        return $payload;
    }
}
