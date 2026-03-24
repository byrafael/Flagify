<?php

declare(strict_types=1);

namespace Flagify\Http;

use Flagify\Auth\ApiKeyAuthenticator;
use Flagify\Auth\ApiKeyGenerator;
use Flagify\Auth\ApiPrincipal;
use Flagify\Auth\ScopeAuthorizer;
use Flagify\Domain\KeyKind;
use Flagify\Repository\ApiKeyRepository;
use Flagify\Repository\AuditLogRepository;
use Flagify\Repository\ClientRepository;
use Flagify\Repository\CodeReferenceRepository;
use Flagify\Repository\ChangeRequestRepository;
use Flagify\Repository\EnvironmentRepository;
use Flagify\Repository\EvaluationEventRepository;
use Flagify\Repository\FlagEnvironmentRepository;
use Flagify\Repository\FlagRepository;
use Flagify\Repository\IdentityRepository;
use Flagify\Repository\IdentityTraitRepository;
use Flagify\Repository\OverrideRepository;
use Flagify\Repository\ProjectRepository;
use Flagify\Repository\SegmentRepository;
use Flagify\Service\AnalyticsService;
use Flagify\Service\AuditLogService;
use Flagify\Service\ChangeRequestService;
use Flagify\Service\CodeReferenceService;
use Flagify\Service\FlagValueValidator;
use Flagify\Service\IdentityService;
use Flagify\Service\ImportExportService;
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
        private readonly EnvironmentRepository $environments,
        private readonly SegmentRepository $segments,
        private readonly FlagEnvironmentRepository $flagEnvironments,
        private readonly OverrideRepository $overrides,
        private readonly EvaluationEventRepository $events,
        private readonly ApiKeyRepository $apiKeys,
        private readonly FlagValueValidator $validator,
        private readonly ResolvedConfigService $resolvedConfig,
        private readonly IdentityRepository $identities,
        private readonly IdentityTraitRepository $identityTraits,
        private readonly AuditLogRepository $auditLogs,
        private readonly ChangeRequestRepository $changeRequests,
        private readonly CodeReferenceRepository $codeReferences,
        private readonly AnalyticsService $analytics,
        private readonly IdentityService $identityService,
        private readonly AuditLogService $auditLogService,
        private readonly ChangeRequestService $changeRequestService,
        private readonly ImportExportService $importExportService,
        private readonly CodeReferenceService $codeReferenceService
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
                ], static fn (mixed $value): bool => $value !== null),
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
        if (preg_match('#^/api/v1/projects/([^/]+)/environments$#', $path, $m) === 1) {
            return match ($method) {
                'POST' => $this->createEnvironment($principal, $m[1]),
                'GET' => $this->listEnvironments($principal, $m[1]),
                default => throw new ApiError('not_found', 'Route not found', 404),
            };
        }
        if (preg_match('#^/api/v1/projects/([^/]+)/environments/([^/]+)$#', $path, $m) === 1) {
            return match ($method) {
                'GET' => $this->showEnvironment($principal, $m[1], $m[2]),
                'PATCH' => $this->updateEnvironment($principal, $m[1], $m[2]),
                'DELETE' => $this->deleteEnvironment($principal, $m[1], $m[2]),
                default => throw new ApiError('not_found', 'Route not found', 404),
            };
        }
        if (preg_match('#^/api/v1/projects/([^/]+)/segments$#', $path, $m) === 1) {
            return match ($method) {
                'POST' => $this->createSegment($principal, $m[1]),
                'GET' => $this->listSegments($principal, $m[1]),
                default => throw new ApiError('not_found', 'Route not found', 404),
            };
        }
        if (preg_match('#^/api/v1/projects/([^/]+)/segments/([^/]+)$#', $path, $m) === 1) {
            return match ($method) {
                'GET' => $this->showSegment($principal, $m[1], $m[2]),
                'PATCH' => $this->updateSegment($principal, $m[1], $m[2]),
                'DELETE' => $this->deleteSegment($principal, $m[1], $m[2]),
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
        if (preg_match('#^/api/v1/projects/([^/]+)/flags/([^/]+)/environments/([^/]+)$#', $path, $m) === 1) {
            return match ($method) {
                'GET' => $this->showFlagEnvironment($principal, $m[1], $m[2], $m[3]),
                'PUT' => $this->putFlagEnvironment($principal, $m[1], $m[2], $m[3]),
                'DELETE' => $this->deleteFlagEnvironment($principal, $m[1], $m[2], $m[3]),
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
        if (preg_match('#^/api/v1/projects/([^/]+)/identities$#', $path, $m) === 1) {
            return match ($method) {
                'POST' => $this->createIdentity($principal, $m[1]),
                'GET' => $this->listIdentities($principal, $m[1]),
                default => throw new ApiError('not_found', 'Route not found', 404),
            };
        }
        if (preg_match('#^/api/v1/projects/([^/]+)/identities/([^/]+)$#', $path, $m) === 1) {
            return match ($method) {
                'GET' => $this->showIdentity($principal, $m[1], $m[2]),
                'PATCH' => $this->updateIdentity($principal, $m[1], $m[2]),
                'DELETE' => $this->deleteIdentity($principal, $m[1], $m[2]),
                default => throw new ApiError('not_found', 'Route not found', 404),
            };
        }
        if (preg_match('#^/api/v1/projects/([^/]+)/identities/([^/]+)/traits$#', $path, $m) === 1) {
            return match ($method) {
                'GET' => $this->listIdentityTraits($principal, $m[1], $m[2]),
                'PATCH' => $this->patchIdentityTraits($principal, $m[1], $m[2]),
                default => throw new ApiError('not_found', 'Route not found', 404),
            };
        }
        if (preg_match('#^/api/v1/projects/([^/]+)/identities/([^/]+)/traits/([^/]+)$#', $path, $m) === 1 && $method === 'PUT') {
            return $this->putIdentityTrait($principal, $m[1], $m[2], $m[3]);
        }
        if (preg_match('#^/api/v1/projects/([^/]+)/audit-logs$#', $path, $m) === 1 && $method === 'GET') {
            return $this->listAuditLogs($principal, $m[1]);
        }
        if (preg_match('#^/api/v1/projects/([^/]+)/analytics/evaluations/by-flag$#', $path, $m) === 1 && $method === 'GET') {
            return $this->analyticsByFlag($principal, $m[1]);
        }
        if (preg_match('#^/api/v1/projects/([^/]+)/analytics/evaluations/by-variant$#', $path, $m) === 1 && $method === 'GET') {
            return $this->analyticsByVariant($principal, $m[1]);
        }
        if (preg_match('#^/api/v1/projects/([^/]+)/analytics/evaluations/by-environment$#', $path, $m) === 1 && $method === 'GET') {
            return $this->analyticsByEnvironment($principal, $m[1]);
        }
        if (preg_match('#^/api/v1/projects/([^/]+)/analytics/recent-activity$#', $path, $m) === 1 && $method === 'GET') {
            return $this->analyticsRecentActivity($principal, $m[1]);
        }
        if (preg_match('#^/api/v1/projects/([^/]+)/change-requests$#', $path, $m) === 1) {
            return match ($method) {
                'POST' => $this->createChangeRequest($principal, $m[1]),
                'GET' => $this->listChangeRequests($principal, $m[1]),
                default => throw new ApiError('not_found', 'Route not found', 404),
            };
        }
        if (preg_match('#^/api/v1/projects/([^/]+)/change-requests/([^/]+)$#', $path, $m) === 1 && $method === 'GET') {
            return $this->showChangeRequest($principal, $m[1], $m[2]);
        }
        if (preg_match('#^/api/v1/projects/([^/]+)/change-requests/([^/]+)/review$#', $path, $m) === 1 && $method === 'POST') {
            return $this->reviewChangeRequest($principal, $m[1], $m[2]);
        }
        if (preg_match('#^/api/v1/projects/([^/]+)/change-requests/([^/]+)/apply$#', $path, $m) === 1 && $method === 'POST') {
            return $this->applyChangeRequest($principal, $m[1], $m[2]);
        }
        if (preg_match('#^/api/v1/projects/([^/]+)/export$#', $path, $m) === 1 && $method === 'GET') {
            return $this->exportProjectConfig($principal, $m[1]);
        }
        if (preg_match('#^/api/v1/projects/([^/]+)/import$#', $path, $m) === 1 && $method === 'POST') {
            return $this->importProjectConfig($principal, $m[1]);
        }
        if (preg_match('#^/api/v1/projects/([^/]+)/code-references$#', $path, $m) === 1) {
            return match ($method) {
                'POST' => $this->createCodeReferences($principal, $m[1]),
                'GET' => $this->listCodeReferences($principal, $m[1]),
                default => throw new ApiError('not_found', 'Route not found', 404),
            };
        }
        if (preg_match('#^/api/v1/projects/([^/]+)/code-references/([^/]+)$#', $path, $m) === 1 && $method === 'DELETE') {
            return $this->deleteCodeReference($principal, $m[1], $m[2]);
        }
        if (preg_match('#^/api/v1/projects/([^/]+)/flags/stale-report$#', $path, $m) === 1 && $method === 'GET') {
            return $this->staleFlagReport($principal, $m[1]);
        }
        if (preg_match('#^/api/v1/projects/([^/]+)/evaluation-events$#', $path, $m) === 1 && $method === 'GET') {
            return $this->listEvaluationEvents($principal, $m[1]);
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
            return $this->projectClientConfig($principal, $m[1], $m[2], $this->query('environment'));
        }
        if (preg_match('#^/api/v1/runtime/projects/([^/]+)/clients/([^/]+)/config/([^/]+)$#', $path, $m) === 1 && $method === 'GET') {
            return $this->projectClientFlag($principal, $m[1], $m[2], $m[3], $this->query('environment'));
        }
        if (preg_match('#^/api/v1/runtime/projects/([^/]+)/environments/([^/]+)/clients/([^/]+)/config$#', $path, $m) === 1 && $method === 'GET') {
            return $this->projectClientConfig($principal, $m[1], $m[3], $m[2]);
        }
        if (preg_match('#^/api/v1/runtime/projects/([^/]+)/environments/([^/]+)/clients/([^/]+)/config/([^/]+)$#', $path, $m) === 1 && $method === 'GET') {
            return $this->projectClientFlag($principal, $m[1], $m[3], $m[4], $m[2]);
        }
        if (preg_match('#^/api/v1/runtime/projects/([^/]+)/environments/([^/]+)/identities/evaluate$#', $path, $m) === 1 && $method === 'POST') {
            return $this->evaluateIdentity($principal, $m[1], $m[2]);
        }
        if (preg_match('#^/api/v1/runtime/projects/([^/]+)/environments/([^/]+)/identities/([^/]+)/([^/]+)/config$#', $path, $m) === 1 && $method === 'POST') {
            return $this->projectIdentityConfig($principal, $m[1], $m[2], $m[3], $m[4]);
        }
        if (preg_match('#^/api/v1/runtime/projects/([^/]+)/environments/([^/]+)/identities/([^/]+)/([^/]+)/config/([^/]+)$#', $path, $m) === 1 && $method === 'POST') {
            return $this->projectIdentityFlag($principal, $m[1], $m[2], $m[3], $m[4], $m[5]);
        }
        if (preg_match('#^/api/v1/runtime/projects/([^/]+)/environments/([^/]+)/snapshot$#', $path, $m) === 1 && $method === 'GET') {
            return $this->projectEnvironmentSnapshot($principal, $m[1], $m[2]);
        }
        if (preg_match('#^/api/v1/runtime/projects/([^/]+)/evaluation-events:batch$#', $path, $m) === 1 && $method === 'POST') {
            return $this->batchEvaluationEvents($principal, $m[1]);
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
        $this->environments->createDefaultsForProject($project['id']);
        $this->audit($principal, 'project', $project['id'], $project['slug'], 'created', null, $project, $project['id']);

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

        $updated = $this->projects->update($projectId, $updates);
        $this->audit($principal, 'project', $projectId, $updated['slug'] ?? null, 'updated', $project, $updated, $projectId);

        return new ApiResponse(200, $this->normalize($updated));
    }

    private function deleteProject(ApiPrincipal $principal, string $projectId): ApiResponse
    {
        $this->requireRoot($principal);
        $project = $this->requireProject($projectId);
        $this->projects->softDelete($projectId);
        $this->audit($principal, 'project', $projectId, $project['slug'] ?? null, 'deleted', $project, ['id' => $projectId, 'status' => 'deleted'], $projectId);

        return new ApiResponse(204, null);
    }

    private function createEnvironment(ApiPrincipal $principal, string $projectId): ApiResponse
    {
        $this->authorizer->requireScope($principal, ScopeAuthorizer::FLAGS_WRITE, $projectId);
        $this->assertProjectActive($projectId);
        $payload = $this->body();

        foreach (['key', 'name'] as $field) {
            if (!array_key_exists($field, $payload)) {
                throw new ApiError('validation_failed', sprintf('%s is required', $field), 422);
            }
        }

        $environment = $this->environments->create([
            'project_id' => $projectId,
            'key' => $this->resourceKey($payload, 'key'),
            'name' => $this->requireString($payload, 'name'),
            'description' => $this->nullableString($payload['description'] ?? null),
            'is_default' => $this->boolValue($payload['is_default'] ?? false),
            'requires_change_requests' => $this->boolBody($payload, 'requires_change_requests', false),
            'sort_order' => $this->intValue($payload['sort_order'] ?? 100, 'sort_order', 0, 10000),
        ]);

        foreach ($this->flags->activeByProject($projectId) as $flag) {
            $this->flagEnvironments->createDefaultForFlag($flag['id'], $environment['id'], $flag['default_value'], $flag['default_variant_key'] ?? null);
        }
        $this->audit($principal, 'environment', $environment['id'], $environment['key'], 'created', null, $environment, $projectId, $environment['id']);

        return new ApiResponse(201, $this->normalize($environment));
    }

    private function listEnvironments(ApiPrincipal $principal, string $projectId): ApiResponse
    {
        $this->authorizer->requireScope($principal, ScopeAuthorizer::FLAGS_READ, $projectId);
        $this->assertProjectActive($projectId);
        $limit = $this->limit();
        $offset = $this->offset();

        return new ApiResponse(200, [
            'items' => array_map($this->normalize(...), $this->environments->paginateByProject($projectId, $limit, $offset)),
            'meta' => ['total' => $this->environments->countByProject($projectId), 'limit' => $limit, 'offset' => $offset],
        ]);
    }

    private function showEnvironment(ApiPrincipal $principal, string $projectId, string $environmentId): ApiResponse
    {
        $this->authorizer->requireScope($principal, ScopeAuthorizer::FLAGS_READ, $projectId);
        $this->assertProjectActive($projectId);

        return new ApiResponse(200, $this->normalize($this->requireEnvironment($projectId, $environmentId)));
    }

    private function updateEnvironment(ApiPrincipal $principal, string $projectId, string $environmentId): ApiResponse
    {
        $this->authorizer->requireScope($principal, ScopeAuthorizer::FLAGS_WRITE, $projectId);
        $environment = $this->requireEnvironment($projectId, $environmentId);
        if ($environment['status'] === 'deleted') {
            throw new ApiError('resource_deleted', 'Environment has been deleted', 410);
        }

        $payload = $this->body();
        $updates = [];
        if (array_key_exists('key', $payload)) {
            $updates['key'] = $this->resourceKey($payload, 'key');
        }
        if (array_key_exists('name', $payload)) {
            $updates['name'] = $this->requireString($payload, 'name');
        }
        if (array_key_exists('description', $payload)) {
            $updates['description'] = $this->nullableString($payload['description']);
        }
        if (array_key_exists('sort_order', $payload)) {
            $updates['sort_order'] = $this->intValue($payload['sort_order'], 'sort_order', 0, 10000);
        }
        if (array_key_exists('is_default', $payload)) {
            $updates['is_default'] = $this->boolValue($payload['is_default']);
        }
        if (array_key_exists('requires_change_requests', $payload)) {
            $updates['requires_change_requests'] = $this->boolValue($payload['requires_change_requests']);
        }

        $updated = $this->environments->update($projectId, $environmentId, $updates);
        $this->audit($principal, 'environment', $environmentId, $updated['key'] ?? null, 'updated', $environment, $updated, $projectId, $environmentId);

        return new ApiResponse(200, $this->normalize($updated));
    }

    private function deleteEnvironment(ApiPrincipal $principal, string $projectId, string $environmentId): ApiResponse
    {
        $this->authorizer->requireScope($principal, ScopeAuthorizer::FLAGS_WRITE, $projectId);
        $environment = $this->requireEnvironment($projectId, $environmentId);
        if ((int) $environment['is_default'] === 1) {
            throw new ApiError('unsupported_operation', 'Default environment cannot be deleted', 409);
        }

        $this->environments->softDelete($projectId, $environmentId);
        $this->audit($principal, 'environment', $environmentId, $environment['key'] ?? null, 'deleted', $environment, ['id' => $environmentId, 'status' => 'deleted'], $projectId, $environmentId);

        return new ApiResponse(204, null);
    }

    private function createSegment(ApiPrincipal $principal, string $projectId): ApiResponse
    {
        $this->authorizer->requireScope($principal, ScopeAuthorizer::FLAGS_WRITE, $projectId);
        $this->assertProjectActive($projectId);
        $payload = $this->body();
        foreach (['key', 'name', 'rules'] as $field) {
            if (!array_key_exists($field, $payload)) {
                throw new ApiError('validation_failed', sprintf('%s is required', $field), 422);
            }
        }

        $segment = $this->segments->create([
            'project_id' => $projectId,
            'key' => $this->resourceKey($payload, 'key'),
            'name' => $this->requireString($payload, 'name'),
            'description' => $this->nullableString($payload['description'] ?? null),
            'rules' => $this->conditions($payload['rules'], 'rules'),
        ]);

        $this->audit($principal, 'segment', $segment['id'], $segment['key'], 'created', null, $segment, $projectId);

        return new ApiResponse(201, $this->normalize($segment));
    }

    private function listSegments(ApiPrincipal $principal, string $projectId): ApiResponse
    {
        $this->authorizer->requireScope($principal, ScopeAuthorizer::FLAGS_READ, $projectId);
        $this->assertProjectActive($projectId);
        $limit = $this->limit();
        $offset = $this->offset();

        return new ApiResponse(200, [
            'items' => array_map($this->normalize(...), $this->segments->paginateByProject($projectId, $limit, $offset)),
            'meta' => ['total' => $this->segments->countByProject($projectId), 'limit' => $limit, 'offset' => $offset],
        ]);
    }

    private function showSegment(ApiPrincipal $principal, string $projectId, string $segmentId): ApiResponse
    {
        $this->authorizer->requireScope($principal, ScopeAuthorizer::FLAGS_READ, $projectId);
        $this->assertProjectActive($projectId);

        return new ApiResponse(200, $this->normalize($this->requireSegment($projectId, $segmentId)));
    }

    private function updateSegment(ApiPrincipal $principal, string $projectId, string $segmentId): ApiResponse
    {
        $this->authorizer->requireScope($principal, ScopeAuthorizer::FLAGS_WRITE, $projectId);
        $this->assertProjectActive($projectId);
        $this->requireSegment($projectId, $segmentId);
        $payload = $this->body();

        $updates = [];
        if (array_key_exists('key', $payload)) {
            $updates['key'] = $this->resourceKey($payload, 'key');
        }
        if (array_key_exists('name', $payload)) {
            $updates['name'] = $this->requireString($payload, 'name');
        }
        if (array_key_exists('description', $payload)) {
            $updates['description'] = $this->nullableString($payload['description']);
        }
        if (array_key_exists('rules', $payload)) {
            $updates['rules'] = $this->conditions($payload['rules'], 'rules');
        }

        $before = $this->requireSegment($projectId, $segmentId);
        $updated = $this->segments->update($projectId, $segmentId, $updates);
        $this->audit($principal, 'segment', $segmentId, $updated['key'] ?? null, 'updated', $before, $updated, $projectId);

        return new ApiResponse(200, $this->normalize($updated));
    }

    private function deleteSegment(ApiPrincipal $principal, string $projectId, string $segmentId): ApiResponse
    {
        $this->authorizer->requireScope($principal, ScopeAuthorizer::FLAGS_WRITE, $projectId);
        $this->assertProjectActive($projectId);
        $segment = $this->requireSegment($projectId, $segmentId);
        $this->segments->softDelete($projectId, $segmentId);
        $this->audit($principal, 'segment', $segmentId, $segment['key'] ?? null, 'deleted', $segment, ['id' => $segmentId, 'status' => 'deleted'], $projectId);

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

        $key = $this->resourceKey($payload, 'key');
        $type = $this->requireString($payload, 'type');
        $defaultValue = $payload['default_value'];
        $options = $payload['options'] ?? null;

        $this->validator->validateFlag($type, $defaultValue, $options);
        $variants = $this->validator->validateVariants($type, $options, $payload['variants'] ?? null, $this->nullableString($payload['default_variant_key'] ?? null));
        $prerequisites = $this->validator->validatePrerequisites($payload['prerequisites'] ?? null);

        $flag = $this->flags->create([
            'project_id' => $projectId,
            'key' => $key,
            'name' => $this->requireString($payload, 'name'),
            'description' => $this->nullableString($payload['description'] ?? null),
            'flag_kind' => $this->flagKind($payload['flag_kind'] ?? 'release'),
            'type' => $type,
            'default_value' => $defaultValue,
            'options' => $options,
            'variants' => $variants,
            'default_variant_key' => $this->nullableString($payload['default_variant_key'] ?? null),
            'expires_at' => $this->nullableString($payload['expires_at'] ?? null),
            'prerequisites' => $prerequisites,
        ]);

        foreach ($this->environments->allActiveByProject($projectId) as $environment) {
            $this->flagEnvironments->createDefaultForFlag($flag['id'], $environment['id'], $defaultValue, $flag['default_variant_key'] ?? null);
        }
        $this->audit($principal, 'flag', $flag['id'], $flag['key'], 'created', null, $flag, $projectId);

        return new ApiResponse(201, $this->normalize($flag));
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
            $payload['key'] = $this->resourceKey($payload, 'key');
        }
        if (array_key_exists('status', $payload) && !in_array($payload['status'], ['active', 'archived'], true)) {
            throw new ApiError('validation_failed', 'status must be active or archived', 422);
        }
        if (array_key_exists('flag_kind', $payload)) {
            $payload['flag_kind'] = $this->flagKind($payload['flag_kind']);
        }
        if (array_key_exists('description', $payload)) {
            $payload['description'] = $this->nullableString($payload['description']);
        }
        if (array_key_exists('expires_at', $payload)) {
            $payload['expires_at'] = $this->nullableString($payload['expires_at']);
        }

        $defaultValue = array_key_exists('default_value', $payload) ? $payload['default_value'] : $flag['default_value'];
        $options = array_key_exists('options', $payload) ? $payload['options'] : $flag['options'];
        $variants = array_key_exists('variants', $payload)
            ? $this->validator->validateVariants($flag['type'], $options, $payload['variants'], $this->nullableString($payload['default_variant_key'] ?? $flag['default_variant_key'] ?? null))
            : $flag['variants'];
        $defaultVariantKey = array_key_exists('default_variant_key', $payload)
            ? $this->nullableString($payload['default_variant_key'])
            : ($flag['default_variant_key'] ?? null);

        $overrideValues = $this->overrides->valuesForFlag($flagId);
        $this->validator->assertOptionsCompatible($flag['type'], $defaultValue, $options, $overrideValues);
        if ($variants !== null) {
            $this->validator->validateVariants($flag['type'], $options, $variants, $defaultVariantKey);
        }
        if (array_key_exists('prerequisites', $payload)) {
            $payload['prerequisites'] = $this->validator->validatePrerequisites($payload['prerequisites']);
        }
        if (array_key_exists('default_variant_key', $payload) || $defaultVariantKey !== null) {
            $payload['default_variant_key'] = $defaultVariantKey;
        }
        if (array_key_exists('variants', $payload)) {
            $payload['variants'] = $variants;
        }

        $updated = $this->flags->update($projectId, $flagId, $payload);
        if (array_key_exists('default_value', $payload) || array_key_exists('default_variant_key', $payload)) {
            foreach ($this->flagEnvironments->forFlag($flagId) as $config) {
                $matchesOldDefault = $config['default_value'] === $flag['default_value']
                    && (($config['default_variant_key'] ?? null) === ($flag['default_variant_key'] ?? null));
                if (!$matchesOldDefault) {
                    continue;
                }

                $this->flagEnvironments->upsert($flagId, $config['environment_id'], [
                    'default_value' => $updated['default_value'],
                    'default_variant_key' => $updated['default_variant_key'] ?? null,
                    'rules' => $config['rules'] ?? [],
                ]);
            }
        }

        $this->audit($principal, 'flag', $flagId, $updated['key'] ?? null, 'updated', $flag, $updated, $projectId);

        return new ApiResponse(200, $this->normalize($updated));
    }

    private function deleteFlag(ApiPrincipal $principal, string $projectId, string $flagId): ApiResponse
    {
        $this->authorizer->requireScope($principal, ScopeAuthorizer::FLAGS_WRITE, $projectId);
        $this->assertProjectActive($projectId);
        $flag = $this->requireFlag($projectId, $flagId);

        if ($this->overrides->hasAnyForFlag($flagId)) {
            $this->flags->update($projectId, $flagId, ['status' => 'archived']);
        } else {
            foreach ($this->flagEnvironments->forFlag($flagId) as $config) {
                $this->flagEnvironments->delete($flagId, $config['environment_id']);
            }
            $this->flags->delete($projectId, $flagId);
        }
        $this->audit($principal, 'flag', $flagId, $flag['key'] ?? null, 'deleted', $flag, ['id' => $flagId, 'status' => 'deleted'], $projectId);

        return new ApiResponse(204, null);
    }

    private function showFlagEnvironment(ApiPrincipal $principal, string $projectId, string $flagId, string $environmentKey): ApiResponse
    {
        $this->authorizer->requireScope($principal, ScopeAuthorizer::FLAGS_READ, $projectId);
        $flag = $this->requireFlag($projectId, $flagId);
        $environment = $this->requireEnvironmentByKey($projectId, $environmentKey);
        $config = $this->flagEnvironments->find($flag['id'], $environment['id']);
        if ($config === null) {
            $config = [
                'flag_id' => $flag['id'],
                'environment_id' => $environment['id'],
                'default_value' => $flag['default_value'],
                'default_variant_key' => $flag['default_variant_key'] ?? null,
                'rules' => [],
            ];
        }

        return new ApiResponse(200, $this->normalize([
            ...$config,
            'environment' => $environment,
        ]));
    }

    private function putFlagEnvironment(ApiPrincipal $principal, string $projectId, string $flagId, string $environmentKey): ApiResponse
    {
        $this->authorizer->requireScope($principal, ScopeAuthorizer::FLAGS_WRITE, $projectId);
        $flag = $this->requireFlag($projectId, $flagId);
        $environment = $this->requireEnvironmentByKey($projectId, $environmentKey);
        if ((int) ($environment['requires_change_requests'] ?? 0) === 1) {
            throw new ApiError('conflict', 'Protected environments require a change request for live config mutations', 409);
        }
        $payload = $this->body();

        $defaultValue = array_key_exists('default_value', $payload) ? $payload['default_value'] : $flag['default_value'];
        $defaultVariantKey = array_key_exists('default_variant_key', $payload)
            ? $this->nullableString($payload['default_variant_key'])
            : ($flag['default_variant_key'] ?? null);
        $existingConfig = $this->flagEnvironments->find($flag['id'], $environment['id']);
        $rules = array_key_exists('rules', $payload)
            ? $this->targetingRules($payload['rules'], $flag)
            : (is_array($existingConfig) ? ($existingConfig['rules'] ?? []) : []);

        $this->validator->validateValue($flag['type'], $defaultValue, $flag['options']);
        if ($flag['variants'] !== null) {
            $this->validator->validateVariants($flag['type'], $flag['options'], $flag['variants'], $defaultVariantKey);
        } elseif ($defaultVariantKey !== null) {
            throw new ApiError('validation_failed', 'default_variant_key requires variants on the flag', 422);
        }

        $config = $this->flagEnvironments->upsert($flag['id'], $environment['id'], [
            'default_value' => $defaultValue,
            'default_variant_key' => $defaultVariantKey,
            'rules' => $rules,
        ]);
        $this->audit($principal, 'flag_environment_config', $config['id'] ?? $flag['id'], $flag['key'], 'updated', $existingConfig, $config, $projectId, $environment['id']);

        return new ApiResponse(200, $this->normalize([
            ...$config,
            'environment' => $environment,
        ]));
    }

    private function deleteFlagEnvironment(ApiPrincipal $principal, string $projectId, string $flagId, string $environmentKey): ApiResponse
    {
        $this->authorizer->requireScope($principal, ScopeAuthorizer::FLAGS_WRITE, $projectId);
        $flag = $this->requireFlag($projectId, $flagId);
        $environment = $this->requireEnvironmentByKey($projectId, $environmentKey);
        if ((int) ($environment['requires_change_requests'] ?? 0) === 1) {
            throw new ApiError('conflict', 'Protected environments require a change request for live config mutations', 409);
        }
        $before = $this->flagEnvironments->find($flag['id'], $environment['id']);
        $this->flagEnvironments->delete($flag['id'], $environment['id']);
        $config = $this->flagEnvironments->createDefaultForFlag($flag['id'], $environment['id'], $flag['default_value'], $flag['default_variant_key'] ?? null);
        $this->audit($principal, 'flag_environment_config', $config['id'] ?? $flag['id'], $flag['key'], 'deleted', $before, $config, $projectId, $environment['id']);

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

        $client = $this->clients->create([
            'project_id' => $projectId,
            'key' => $this->resourceKey($payload, 'key'),
            'name' => $this->requireString($payload, 'name'),
            'description' => $this->nullableString($payload['description'] ?? null),
            'metadata' => $this->metadata($payload['metadata'] ?? []),
        ]);
        $this->identityService->syncClientIdentity($projectId, $client);
        $this->audit($principal, 'client', $client['id'], $client['key'], 'created', null, $client, $projectId);

        return new ApiResponse(201, $this->normalize($client));
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
        $before = $this->requireClient($projectId, $clientId);
        $payload = $this->body();

        if (array_key_exists('key', $payload)) {
            $payload['key'] = $this->resourceKey($payload, 'key');
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

        $updated = $this->clients->update($projectId, $clientId, $payload);
        $this->identityService->syncClientIdentity($projectId, $updated);
        $this->audit($principal, 'client', $clientId, $updated['key'] ?? null, 'updated', $before, $updated, $projectId);

        return new ApiResponse(200, $this->normalize($updated));
    }

    private function deleteClient(ApiPrincipal $principal, string $projectId, string $clientId): ApiResponse
    {
        $this->authorizer->requireScope($principal, ScopeAuthorizer::CLIENTS_WRITE, $projectId);
        $this->assertProjectActive($projectId);
        $client = $this->requireClient($projectId, $clientId);
        $this->clients->softDelete($projectId, $clientId);
        $updated = $this->clients->find($projectId, $clientId);
        if ($updated !== null) {
            $this->identityService->syncClientIdentity($projectId, $updated);
        }
        $this->audit($principal, 'client', $clientId, $client['key'] ?? null, 'deleted', $client, ['id' => $clientId, 'status' => 'deleted'], $projectId);

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

        $override = $this->overrides->upsert($projectId, $flagId, $clientId, $payload['value']);
        $this->audit($principal, 'client_override', $override['id'] ?? $flagId . ':' . $clientId, $flag['key'], 'updated', null, $override, $projectId);

        return new ApiResponse(200, $this->normalize($override));
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
        $flag = $this->requireFlag($projectId, $flagId);
        $before = $this->overrides->find($flagId, $clientId);
        $this->overrides->delete($flagId, $clientId);
        $this->audit($principal, 'client_override', $before['id'] ?? $flagId . ':' . $clientId, $flag['key'], 'deleted', $before, null, $projectId);

        return new ApiResponse(204, null);
    }

    private function createIdentity(ApiPrincipal $principal, string $projectId): ApiResponse
    {
        $this->authorizer->requireScope($principal, ScopeAuthorizer::CLIENTS_WRITE, $projectId);
        $this->assertProjectActive($projectId);
        $payload = $this->body();
        $traits = $this->identityService->assertTraitPayload($payload['traits'] ?? []);
        $identity = $this->identityService->create($projectId, [
            'kind' => $this->identityKind($payload['kind'] ?? null),
            'identifier' => $this->identityIdentifier($payload['identifier'] ?? null),
            'display_name' => $this->nullableString($payload['display_name'] ?? null),
            'description' => $this->nullableString($payload['description'] ?? null),
            'traits' => $traits,
        ]);
        $this->audit($principal, 'identity', $identity['id'], $identity['identifier'], 'created', null, $identity, $projectId);

        return new ApiResponse(201, $this->normalize($identity));
    }

    private function listIdentities(ApiPrincipal $principal, string $projectId): ApiResponse
    {
        $this->authorizer->requireScope($principal, ScopeAuthorizer::CLIENTS_READ, $projectId);
        $limit = $this->limit();
        $offset = $this->offset();
        $kind = $this->query('kind');
        $identifier = $this->query('identifier');
        $status = $this->query('status');
        $items = $this->identities->paginateByProject($projectId, $limit, $offset, $kind, $identifier, $status);
        foreach ($items as &$item) {
            $item['traits'] = $this->identityService->traitMap($item['id']);
        }

        return new ApiResponse(200, [
            'items' => array_map($this->normalize(...), $items),
            'meta' => [
                'total' => $this->identities->countByProject($projectId, $kind, $identifier, $status),
                'limit' => $limit,
                'offset' => $offset,
            ],
        ]);
    }

    private function showIdentity(ApiPrincipal $principal, string $projectId, string $identityId): ApiResponse
    {
        $this->authorizer->requireScope($principal, ScopeAuthorizer::CLIENTS_READ, $projectId);

        return new ApiResponse(200, $this->normalize($this->identityService->get($projectId, $identityId)));
    }

    private function updateIdentity(ApiPrincipal $principal, string $projectId, string $identityId): ApiResponse
    {
        $this->authorizer->requireScope($principal, ScopeAuthorizer::CLIENTS_WRITE, $projectId);
        $before = $this->requireIdentity($projectId, $identityId);
        $payload = $this->body();
        $updates = [];
        if (array_key_exists('kind', $payload)) {
            $updates['kind'] = $this->identityKind($payload['kind']);
        }
        if (array_key_exists('identifier', $payload)) {
            $updates['identifier'] = $this->identityIdentifier($payload['identifier']);
        }
        if (array_key_exists('display_name', $payload)) {
            $updates['display_name'] = $this->nullableString($payload['display_name']);
        }
        if (array_key_exists('description', $payload)) {
            $updates['description'] = $this->nullableString($payload['description']);
        }
        if (array_key_exists('status', $payload)) {
            $updates['status'] = $this->identityStatus($payload['status']);
        }
        $updated = $this->identities->update($projectId, $identityId, $updates);
        $updated['traits'] = $this->identityService->traitMap($identityId);
        $this->audit($principal, 'identity', $identityId, $updated['identifier'] ?? null, 'updated', $before, $updated, $projectId);

        return new ApiResponse(200, $this->normalize($updated));
    }

    private function deleteIdentity(ApiPrincipal $principal, string $projectId, string $identityId): ApiResponse
    {
        $this->authorizer->requireScope($principal, ScopeAuthorizer::CLIENTS_WRITE, $projectId);
        $before = $this->identityService->get($projectId, $identityId);
        $deleted = $this->identities->softDelete($projectId, $identityId);
        $this->audit($principal, 'identity', $identityId, $before['identifier'] ?? null, 'deleted', $before, $deleted, $projectId);

        return new ApiResponse(204, null);
    }

    private function listIdentityTraits(ApiPrincipal $principal, string $projectId, string $identityId): ApiResponse
    {
        $this->authorizer->requireScope($principal, ScopeAuthorizer::CLIENTS_READ, $projectId);
        $this->requireIdentity($projectId, $identityId);

        return new ApiResponse(200, [
            'identity_id' => $identityId,
            'traits' => $this->identityService->traitMap($identityId),
        ]);
    }

    private function putIdentityTrait(ApiPrincipal $principal, string $projectId, string $identityId, string $traitKey): ApiResponse
    {
        $this->authorizer->requireScope($principal, ScopeAuthorizer::CLIENTS_WRITE, $projectId);
        $identity = $this->requireIdentity($projectId, $identityId);
        $payload = $this->body();
        if (!array_key_exists('value', $payload)) {
            throw new ApiError('validation_failed', 'value is required', 422);
        }
        $before = $this->identityTraits->find($identityId, $traitKey);
        $trait = $this->identityService->upsertTrait($identityId, $traitKey, $payload['value']);
        $this->audit($principal, 'identity_trait', $trait['id'] ?? ($identityId . ':' . $traitKey), $traitKey, 'updated', $before, $trait, $projectId);

        return new ApiResponse(200, $this->normalize([
            'identity_id' => $identityId,
            'identity' => ['id' => $identity['id'], 'kind' => $identity['kind'], 'identifier' => $identity['identifier']],
            'trait' => $trait,
        ]));
    }

    private function patchIdentityTraits(ApiPrincipal $principal, string $projectId, string $identityId): ApiResponse
    {
        $this->authorizer->requireScope($principal, ScopeAuthorizer::CLIENTS_WRITE, $projectId);
        $this->requireIdentity($projectId, $identityId);
        $before = $this->identityService->traitMap($identityId);
        $payload = $this->body();
        if (array_key_exists('set', $payload)) {
            $this->identityService->assertTraitPayload($payload['set']);
        }
        $traits = $this->identityService->bulkPatchTraits($identityId, [
            'set' => $payload['set'] ?? [],
            'unset' => $payload['unset'] ?? [],
        ]);
        $this->audit($principal, 'identity_trait', $identityId, $identityId, 'updated', $before, $traits, $projectId);

        return new ApiResponse(200, $this->normalize([
            'identity_id' => $identityId,
            'traits' => $traits,
        ]));
    }

    private function listAuditLogs(ApiPrincipal $principal, string $projectId): ApiResponse
    {
        $this->authorizer->requireScope($principal, ScopeAuthorizer::FLAGS_READ, $projectId);
        $limit = $this->limit();
        $offset = $this->offset();
        $environmentId = null;
        if (($environmentKey = $this->query('environment')) !== null) {
            $environmentId = $this->requireEnvironmentByKey($projectId, $environmentKey)['id'];
        }
        $filters = [
            'resource_type' => $this->query('resource_type'),
            'resource_id' => $this->query('resource_id'),
            'resource_key' => $this->query('resource_key'),
            'environment_id' => $environmentId,
            'actor_id' => $this->query('actor_id'),
            'action' => $this->query('action'),
            'created_from' => $this->query('created_from'),
            'created_to' => $this->query('created_to'),
        ];

        return new ApiResponse(200, [
            'items' => array_map($this->normalize(...), $this->auditLogs->paginateByProject($projectId, $limit, $offset, $filters)),
            'meta' => [
                'total' => $this->auditLogs->countByProject($projectId, $filters),
                'limit' => $limit,
                'offset' => $offset,
            ],
        ]);
    }

    private function analyticsByFlag(ApiPrincipal $principal, string $projectId): ApiResponse
    {
        $this->authorizer->requireScope($principal, ScopeAuthorizer::FLAGS_READ, $projectId);
        return new ApiResponse(200, ['items' => $this->normalize($this->analytics->byFlag($projectId, $this->analyticsFilters($projectId), $this->limit()))]);
    }

    private function analyticsByVariant(ApiPrincipal $principal, string $projectId): ApiResponse
    {
        $this->authorizer->requireScope($principal, ScopeAuthorizer::FLAGS_READ, $projectId);
        return new ApiResponse(200, ['items' => $this->normalize($this->analytics->byVariant($projectId, $this->analyticsFilters($projectId), $this->limit()))]);
    }

    private function analyticsByEnvironment(ApiPrincipal $principal, string $projectId): ApiResponse
    {
        $this->authorizer->requireScope($principal, ScopeAuthorizer::FLAGS_READ, $projectId);
        return new ApiResponse(200, ['items' => $this->normalize($this->analytics->byEnvironment($projectId, $this->analyticsFilters($projectId), $this->limit()))]);
    }

    private function analyticsRecentActivity(ApiPrincipal $principal, string $projectId): ApiResponse
    {
        $this->authorizer->requireScope($principal, ScopeAuthorizer::FLAGS_READ, $projectId);
        return new ApiResponse(200, ['items' => $this->normalize($this->analytics->recentActivity($projectId, $this->analyticsFilters($projectId), $this->limit()))]);
    }

    private function createChangeRequest(ApiPrincipal $principal, string $projectId): ApiResponse
    {
        $this->authorizer->requireScope($principal, ScopeAuthorizer::FLAGS_WRITE, $projectId);
        $payload = $this->body();
        $request = $this->changeRequestService->propose($principal, $projectId, [
            'flag_id' => $this->requireString($payload, 'flag_id'),
            'environment_key' => $this->requireString($payload, 'environment_key'),
            'title' => $this->requireString($payload, 'title'),
            'description' => $this->nullableString($payload['description'] ?? null),
            'desired_config' => $this->changeRequestDesiredConfig($payload['desired_config'] ?? null),
            'base_snapshot_checksum' => $this->nullableString($payload['base_snapshot_checksum'] ?? null),
        ]);

        return new ApiResponse(201, $this->normalize($request));
    }

    private function listChangeRequests(ApiPrincipal $principal, string $projectId): ApiResponse
    {
        $this->authorizer->requireScope($principal, ScopeAuthorizer::FLAGS_READ, $projectId);
        $limit = $this->limit();
        $offset = $this->offset();

        return new ApiResponse(200, [
            'items' => array_map($this->normalize(...), $this->changeRequests->paginateByProject($projectId, $limit, $offset)),
            'meta' => ['total' => $this->changeRequests->countByProject($projectId), 'limit' => $limit, 'offset' => $offset],
        ]);
    }

    private function showChangeRequest(ApiPrincipal $principal, string $projectId, string $changeRequestId): ApiResponse
    {
        $this->authorizer->requireScope($principal, ScopeAuthorizer::FLAGS_READ, $projectId);

        return new ApiResponse(200, $this->normalize($this->changeRequestService->require($projectId, $changeRequestId)));
    }

    private function reviewChangeRequest(ApiPrincipal $principal, string $projectId, string $changeRequestId): ApiResponse
    {
        $this->authorizer->requireScope($principal, ScopeAuthorizer::FLAGS_WRITE, $projectId);
        $payload = $this->body();
        $decision = $this->requireString($payload, 'decision');
        if (!in_array($decision, ['approve', 'reject'], true)) {
            throw new ApiError('validation_failed', 'decision must be approve or reject', 422);
        }

        return new ApiResponse(200, $this->normalize($this->changeRequestService->review($principal, $projectId, $changeRequestId, $decision)));
    }

    private function applyChangeRequest(ApiPrincipal $principal, string $projectId, string $changeRequestId): ApiResponse
    {
        $this->authorizer->requireScope($principal, ScopeAuthorizer::FLAGS_WRITE, $projectId);
        $request = $this->changeRequestService->require($projectId, $changeRequestId);
        $approved = $request['approved_payload'] ?? $request['proposed_payload'];
        $environment = $this->requireEnvironmentByKey($projectId, $approved['environment_key']);
        $snapshot = $this->resolvedConfig->buildSnapshot($projectId, $environment);

        return new ApiResponse(200, $this->normalize($this->changeRequestService->apply($principal, $projectId, $changeRequestId, $snapshot['checksum'])));
    }

    private function exportProjectConfig(ApiPrincipal $principal, string $projectId): ApiResponse
    {
        $this->authorizer->requireScope($principal, ScopeAuthorizer::FLAGS_READ, $projectId);

        return new ApiResponse(200, $this->normalize($this->importExportService->exportProject($projectId)));
    }

    private function importProjectConfig(ApiPrincipal $principal, string $projectId): ApiResponse
    {
        $this->authorizer->requireScope($principal, ScopeAuthorizer::FLAGS_WRITE, $projectId);
        $payload = $this->body();
        $dryRun = $this->boolQuery('dry_run', true);
        $replace = $this->boolQuery('replace', false);
        $plan = $this->buildImportPlan($projectId, $payload);
        if ($dryRun) {
            return new ApiResponse(200, $this->normalize(['dry_run' => true, 'plan' => $plan]));
        }
        if ($plan['conflicts'] !== [] && !$replace) {
            throw new ApiError('conflict', 'Import conflicts detected', 409, ['conflicts' => $plan['conflicts']]);
        }

        foreach ($plan['environments'] as $entry) {
            if ($entry['action'] === 'create') {
                $this->environments->create([
                    'project_id' => $projectId,
                    ...$entry['payload'],
                ]);
            } elseif ($replace) {
                $existing = $this->requireEnvironmentByKey($projectId, $entry['key']);
                $this->environments->update($projectId, $existing['id'], $entry['payload']);
            }
        }
        foreach ($plan['segments'] as $entry) {
            if ($entry['action'] === 'create') {
                $this->segments->create(['project_id' => $projectId, ...$entry['payload']]);
            } elseif ($replace) {
                $existing = $this->segments->findByKey($projectId, $entry['key']);
                if ($existing !== null) {
                    $this->segments->update($projectId, $existing['id'], $entry['payload']);
                }
            }
        }
        foreach ($plan['flags'] as $entry) {
            if ($entry['action'] === 'create') {
                $this->flags->create(['project_id' => $projectId, ...$entry['payload']]);
            } elseif ($replace) {
                $existing = $this->flags->findByKey($projectId, $entry['key']);
                if ($existing !== null) {
                    $this->flags->update($projectId, $existing['id'], $entry['payload']);
                }
            }
        }
        foreach ($plan['flag_environment_configs'] as $entry) {
            $flag = $this->flags->findByKey($projectId, $entry['flag_key']);
            $environment = $this->requireEnvironmentByKey($projectId, $entry['environment_key']);
            if ($flag !== null) {
                $this->flagEnvironments->upsert($flag['id'], $environment['id'], $entry['payload']);
            }
        }
        $this->audit($principal, 'import', $projectId, $projectId, 'imported', null, $plan, $projectId);

        return new ApiResponse(200, $this->normalize(['dry_run' => false, 'plan' => $plan]));
    }

    private function createCodeReferences(ApiPrincipal $principal, string $projectId): ApiResponse
    {
        $this->authorizer->requireScope($principal, ScopeAuthorizer::FLAGS_WRITE, $projectId);
        $payload = $this->body();
        if (!is_array($payload['references'] ?? null)) {
            throw new ApiError('validation_failed', 'references must be an array', 422);
        }
        $created = $this->codeReferenceService->ingest($projectId, $payload['references']);
        $this->audit($principal, 'code_reference', $projectId, 'batch', 'created', null, $created, $projectId);

        return new ApiResponse(201, $this->normalize(['items' => $created]));
    }

    private function listCodeReferences(ApiPrincipal $principal, string $projectId): ApiResponse
    {
        $this->authorizer->requireScope($principal, ScopeAuthorizer::FLAGS_READ, $projectId);
        $limit = $this->limit();
        $offset = $this->offset();

        return new ApiResponse(200, [
            'items' => array_map($this->normalize(...), $this->codeReferences->paginateByProject($projectId, $limit, $offset)),
            'meta' => ['total' => $this->codeReferences->countByProject($projectId), 'limit' => $limit, 'offset' => $offset],
        ]);
    }

    private function deleteCodeReference(ApiPrincipal $principal, string $projectId, string $referenceId): ApiResponse
    {
        $this->authorizer->requireScope($principal, ScopeAuthorizer::FLAGS_WRITE, $projectId);
        $reference = $this->codeReferences->find($projectId, $referenceId);
        if ($reference === null) {
            throw new ApiError('not_found', 'Code reference not found', 404);
        }
        $this->codeReferences->delete($projectId, $referenceId);
        $this->audit($principal, 'code_reference', $referenceId, $reference['reference_path'], 'deleted', $reference, null, $projectId);

        return new ApiResponse(204, null);
    }

    private function staleFlagReport(ApiPrincipal $principal, string $projectId): ApiResponse
    {
        $this->authorizer->requireScope($principal, ScopeAuthorizer::FLAGS_READ, $projectId);
        $items = $this->codeReferenceService->staleReport(
            $projectId,
            $this->flags->activeByProject($projectId),
            $this->query('evaluated_before'),
            $this->query('stale_status'),
            array_key_exists('has_code_references', $_GET) ? $this->boolQuery('has_code_references', false) : null
        );

        return new ApiResponse(200, $this->normalize([
            'items' => array_slice($items, $this->offset(), $this->limit()),
            'meta' => ['total' => count($items), 'limit' => $this->limit(), 'offset' => $this->offset()],
        ]));
    }

    private function listEvaluationEvents(ApiPrincipal $principal, string $projectId): ApiResponse
    {
        $this->authorizer->requireScope($principal, ScopeAuthorizer::FLAGS_READ, $projectId);
        $this->assertProjectActive($projectId);
        $limit = $this->limit();
        $offset = $this->offset();

        $environment = null;
        $environmentKey = $this->query('environment');
        if ($environmentKey !== null) {
            $environment = $this->requireEnvironmentByKey($projectId, $environmentKey);
        }
        $flagId = $this->query('flag_id');
        $clientId = $this->query('client_id');

        return new ApiResponse(200, [
            'items' => array_map($this->normalize(...), $this->events->paginateByProject($projectId, $limit, $offset, $environment['id'] ?? null, $flagId, $clientId)),
            'meta' => [
                'total' => $this->events->countByProject($projectId, $environment['id'] ?? null, $flagId, $clientId),
                'limit' => $limit,
                'offset' => $offset,
            ],
        ]);
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

        $this->audit($principal, 'api_key', $key['id'], $key['prefix'], 'created', null, $key, $projectId);

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
        $this->audit($principal, 'api_key', $key['id'], $key['prefix'], 'deleted', $key, ['id' => $key['id'], 'revoked_at' => gmdate('c')], $key['project_id']);

        return new ApiResponse(204, null);
    }

    private function currentRuntimeConfig(ApiPrincipal $principal): ApiResponse
    {
        $principal = $this->authorizer->requireScope($principal, ScopeAuthorizer::RUNTIME_READ, $principal?->projectId, $principal?->clientId);
        if ($principal->projectId === null || $principal->clientId === null) {
            throw new ApiError('forbidden', 'This key is not bound to a runtime client', 403);
        }

        $environment = $this->environmentFromRequest($principal->projectId, $this->query('environment'));

        return new ApiResponse(200, $this->resolvedPayload($principal->projectId, $principal->clientId, $environment, true));
    }

    private function currentRuntimeFlag(ApiPrincipal $principal, string $flagKey): ApiResponse
    {
        $payload = $this->currentRuntimeConfig($principal)->payload();
        if ($payload === null || !array_key_exists($flagKey, $payload['flags'])) {
            throw new ApiError('not_found', 'Flag not found', 404);
        }

        return new ApiResponse(200, ['key' => $flagKey, ...$payload['flags'][$flagKey]]);
    }

    private function projectClientConfig(ApiPrincipal $principal, string $projectId, string $clientKey, ?string $environmentKey): ApiResponse
    {
        $this->authorizer->requireScope($principal, ScopeAuthorizer::RUNTIME_READ_ANY, $projectId);
        $client = $this->clients->findByKey($projectId, $clientKey);
        if ($client === null) {
            throw new ApiError('not_found', 'Project or client not found', 404);
        }
        $environment = $this->environmentFromRequest($projectId, $environmentKey);

        return new ApiResponse(200, $this->resolvedPayload($projectId, $client['id'], $environment, true));
    }

    private function projectClientFlag(ApiPrincipal $principal, string $projectId, string $clientKey, string $flagKey, ?string $environmentKey): ApiResponse
    {
        $payload = $this->projectClientConfig($principal, $projectId, $clientKey, $environmentKey)->payload();
        if ($payload === null || !array_key_exists($flagKey, $payload['flags'])) {
            throw new ApiError('not_found', 'Flag not found', 404);
        }

        return new ApiResponse(200, ['key' => $flagKey, ...$payload['flags'][$flagKey]]);
    }

    private function evaluateIdentity(ApiPrincipal $principal, string $projectId, string $environmentKey): ApiResponse
    {
        $this->authorizer->requireScope($principal, ScopeAuthorizer::RUNTIME_READ_ANY, $projectId);
        $payload = $this->body();
        $environment = $this->requireEnvironmentByKey($projectId, $environmentKey);
        $identity = $this->requireIdentityByKindAndIdentifier(
            $projectId,
            $this->identityKind($payload['kind'] ?? null),
            $this->identityIdentifier($payload['identifier'] ?? null)
        );
        $resolved = $this->resolvedConfig->resolveProjectIdentity(
            $projectId,
            $environment,
            $identity,
            true,
            $this->transientTraitsPayload($payload)
        );
        if (is_array($payload['flag_keys'] ?? null)) {
            $resolved['flags'] = array_intersect_key($resolved['flags'], array_fill_keys($payload['flag_keys'], true));
        }

        return new ApiResponse(200, $this->normalize($resolved));
    }

    private function projectIdentityConfig(ApiPrincipal $principal, string $projectId, string $environmentKey, string $kind, string $identifier): ApiResponse
    {
        $this->authorizer->requireScope($principal, ScopeAuthorizer::RUNTIME_READ_ANY, $projectId);
        $environment = $this->requireEnvironmentByKey($projectId, $environmentKey);
        $payload = $this->body();
        $identity = $this->requireIdentityByKindAndIdentifier($projectId, $kind, $identifier);
        $resolved = $this->resolvedConfig->resolveProjectIdentity(
            $projectId,
            $environment,
            $identity,
            true,
            $this->transientTraitsPayload($payload)
        );
        if (is_array($payload['flag_keys'] ?? null)) {
            $resolved['flags'] = array_intersect_key($resolved['flags'], array_fill_keys($payload['flag_keys'], true));
        }

        return new ApiResponse(200, $this->normalize($resolved));
    }

    private function projectIdentityFlag(ApiPrincipal $principal, string $projectId, string $environmentKey, string $kind, string $identifier, string $flagKey): ApiResponse
    {
        $payload = $this->projectIdentityConfig($principal, $projectId, $environmentKey, $kind, $identifier)->payload();
        if ($payload === null || !array_key_exists($flagKey, $payload['flags'])) {
            throw new ApiError('not_found', 'Flag not found', 404);
        }

        return new ApiResponse(200, ['key' => $flagKey, ...$payload['flags'][$flagKey]]);
    }

    private function projectEnvironmentSnapshot(ApiPrincipal $principal, string $projectId, string $environmentKey): ApiResponse
    {
        $this->authorizer->requireScope($principal, ScopeAuthorizer::RUNTIME_READ_ANY, $projectId);
        $environment = $this->requireEnvironmentByKey($projectId, $environmentKey);
        $snapshot = $this->resolvedConfig->buildSnapshot($projectId, $environment);
        $etag = '"' . $snapshot['checksum'] . '"';
        $ifNoneMatch = trim($_SERVER['HTTP_IF_NONE_MATCH'] ?? '');
        if ($ifNoneMatch !== '' && trim($ifNoneMatch, '"') === $snapshot['checksum']) {
            return new ApiResponse(304, null, ['ETag' => $etag]);
        }

        return new ApiResponse(200, $this->normalize($snapshot['payload']), ['ETag' => $etag]);
    }

    private function batchEvaluationEvents(ApiPrincipal $principal, string $projectId): ApiResponse
    {
        $this->authorizer->requireScope($principal, ScopeAuthorizer::RUNTIME_READ_ANY, $projectId);
        $payload = $this->body();
        $environment = $this->requireEnvironmentByKey($projectId, $this->requireString($payload, 'environment_key'));
        if (!is_array($payload['events'] ?? null)) {
            throw new ApiError('validation_failed', 'events must be an array', 422);
        }

        $created = [];
        foreach ($payload['events'] as $entry) {
            if (!is_array($entry)) {
                throw new ApiError('validation_failed', 'events must contain objects', 422);
            }
            $flag = $this->flags->findByKey($projectId, $this->requireString($entry, 'flag_key'));
            if ($flag === null) {
                throw new ApiError('not_found', 'Flag not found for event batch', 404);
            }
            $client = null;
            if (isset($entry['client_key']) && is_string($entry['client_key']) && trim($entry['client_key']) !== '') {
                $client = $this->clients->findByKey($projectId, trim($entry['client_key']));
            }
            $identity = null;
            if (isset($entry['identity_kind'], $entry['identity_identifier']) && is_string($entry['identity_kind']) && is_string($entry['identity_identifier'])) {
                $identity = $this->identities->findByKindAndIdentifier($projectId, trim($entry['identity_kind']), trim($entry['identity_identifier']));
            }
            $created[] = $this->events->create([
                'project_id' => $projectId,
                'environment_id' => $environment['id'],
                'flag_id' => $flag['id'],
                'client_id' => $client['id'] ?? null,
                'identity_id' => $identity['id'] ?? null,
                'identity_kind' => $entry['identity_kind'] ?? ($identity['kind'] ?? null),
                'identity_identifier' => $entry['identity_identifier'] ?? ($identity['identifier'] ?? null),
                'variant_key' => $entry['variant_key'] ?? null,
                'value' => $entry['value'] ?? null,
                'reason' => $entry['reason'] ?? 'batch_ingest',
                'matched_rule' => $entry['matched_rule'] ?? null,
                'context' => [
                    'snapshot_checksum' => $payload['snapshot_checksum'] ?? null,
                    'environment_key' => $environment['key'],
                ],
                'traits' => $entry['traits'] ?? null,
                'transient_traits' => $entry['transient_traits'] ?? null,
                'created_at' => $this->databaseTimestamp($entry['occurred_at'] ?? null) ?? gmdate('Y-m-d H:i:s'),
            ]);
        }

        return new ApiResponse(202, $this->normalize([
            'accepted' => count($created),
            'batch_id' => $this->nullableString($payload['batch_id'] ?? null),
        ]));
    }

    private function resolvedPayload(string $projectId, string $clientId, array $environment, bool $logEvents): array
    {
        $project = $this->requireProject($projectId);
        $client = $this->requireClient($projectId, $clientId);
        if ($project['status'] !== 'active' || $client['status'] !== 'active') {
            throw new ApiError('unauthorized', 'Project or client is inactive', 401);
        }

        $payload = $this->resolvedConfig->resolveProjectClient($projectId, $environment, $client, $logEvents);
        $payload['project']['slug'] = $project['slug'];

        return $this->normalize($payload);
    }

    private function requireIdentity(string $projectId, string $identityId): array
    {
        $identity = $this->identities->find($projectId, $identityId);
        if ($identity === null) {
            throw new ApiError('not_found', 'Identity not found', 404);
        }

        return $identity;
    }

    private function requireIdentityByKindAndIdentifier(string $projectId, string $kind, string $identifier): array
    {
        $identity = $this->identities->findByKindAndIdentifier($projectId, $kind, $identifier);
        if ($identity === null || $identity['status'] === 'deleted') {
            throw new ApiError('not_found', 'Identity not found', 404);
        }

        return $identity;
    }

    private function requireProject(string $projectId): array
    {
        $project = $this->projects->find($projectId);
        if ($project === null) {
            throw new ApiError('not_found', 'Project not found', 404);
        }

        return $project;
    }

    private function requireEnvironment(string $projectId, string $environmentId): array
    {
        $environment = $this->environments->find($projectId, $environmentId);
        if ($environment === null) {
            throw new ApiError('not_found', 'Environment not found', 404);
        }

        return $environment;
    }

    private function requireEnvironmentByKey(string $projectId, string $environmentKey): array
    {
        $environment = $this->environments->findByKey($projectId, $environmentKey);
        if ($environment === null || $environment['status'] === 'deleted') {
            throw new ApiError('not_found', 'Environment not found', 404);
        }

        return $environment;
    }

    private function requireSegment(string $projectId, string $segmentId): array
    {
        $segment = $this->segments->find($projectId, $segmentId);
        if ($segment === null) {
            throw new ApiError('not_found', 'Segment not found', 404);
        }

        return $segment;
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

    private function audit(ApiPrincipal $principal, string $resourceType, string $resourceId, ?string $resourceKey, string $action, mixed $before, mixed $after, string $projectId, ?string $environmentId = null): void
    {
        $this->auditLogService->record($principal, [
            'project_id' => $projectId,
            'environment_id' => $environmentId,
            'resource_type' => $resourceType,
            'resource_id' => $resourceId,
            'resource_key' => $resourceKey,
            'action' => $action,
            'before_payload' => is_array($before) ? $before : null,
            'after_payload' => is_array($after) ? $after : null,
        ]);
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

    private function analyticsFilters(string $projectId): array
    {
        $environmentId = null;
        if (($environment = $this->query('environment')) !== null) {
            $environmentId = $this->requireEnvironmentByKey($projectId, $environment)['id'];
        }
        $flagId = $this->query('flag_id');
        if ($flagId === null && ($flagKey = $this->query('flag_key')) !== null) {
            $flag = $this->flags->findByKey($projectId, $flagKey);
            $flagId = $flag['id'] ?? null;
        }

        return [
            'environment_id' => $environmentId,
            'flag_id' => $flagId,
            'created_from' => $this->query('created_from'),
            'created_to' => $this->query('created_to'),
        ];
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

    private function resourceKey(array $payload, string $field): string
    {
        $value = $this->requireString($payload, $field);
        if (!preg_match('/^[a-z0-9_-]+$/', $value)) {
            throw new ApiError('validation_failed', sprintf('%s must contain only lowercase letters, numbers, underscores, and dashes', $field), 422);
        }

        return $value;
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

    private function conditions(mixed $value, string $field): array
    {
        if (!is_array($value)) {
            throw new ApiError('validation_failed', sprintf('%s must be an array', $field), 422);
        }

        $normalized = [];
        foreach ($value as $index => $condition) {
            if (!is_array($condition)) {
                throw new ApiError('validation_failed', sprintf('%s[%d] must be an object', $field, $index), 422);
            }
            $attribute = $this->requireConditionString($condition, 'attribute', $field, $index);
            $operator = $this->requireConditionString($condition, 'operator', $field, $index);
            $entry = [
                'attribute' => $attribute,
                'operator' => $operator,
            ];
            if (array_key_exists('value', $condition)) {
                $entry['value'] = $condition['value'];
            }
            if (array_key_exists('values', $condition)) {
                if (!is_array($condition['values'])) {
                    throw new ApiError('validation_failed', sprintf('%s[%d].values must be an array', $field, $index), 422);
                }
                $entry['values'] = array_values($condition['values']);
            }

            $normalized[] = $entry;
        }

        return $normalized;
    }

    private function targetingRules(mixed $value, array $flag): array
    {
        if (!is_array($value)) {
            throw new ApiError('validation_failed', 'rules must be an array', 422);
        }

        $normalized = [];
        foreach ($value as $index => $rule) {
            if (!is_array($rule)) {
                throw new ApiError('validation_failed', sprintf('rules[%d] must be an object', $index), 422);
            }

            $entry = [
                'name' => isset($rule['name']) && is_string($rule['name']) && trim($rule['name']) !== '' ? trim($rule['name']) : 'rule-' . ($index + 1),
                'conditions' => $this->conditions($rule['conditions'] ?? [], sprintf('rules[%d].conditions', $index)),
                'segment_keys' => $this->stringArray($rule['segment_keys'] ?? [], sprintf('rules[%d].segment_keys', $index)),
                'bucketing_key' => isset($rule['bucketing_key']) && is_string($rule['bucketing_key']) ? trim($rule['bucketing_key']) : 'key',
                'serve' => $this->serveDefinition($rule['serve'] ?? [], $flag, $index),
            ];

            if (array_key_exists('percentage', $rule)) {
                $entry['percentage'] = $this->percentage($rule['percentage'], sprintf('rules[%d].percentage', $index));
            }
            if (array_key_exists('schedule', $rule)) {
                $entry['schedule'] = $this->schedule($rule['schedule'], sprintf('rules[%d].schedule', $index));
            }

            $normalized[] = $entry;
        }

        return $normalized;
    }

    private function serveDefinition(mixed $value, array $flag, int $index): array
    {
        if (!is_array($value)) {
            throw new ApiError('validation_failed', sprintf('rules[%d].serve must be an object', $index), 422);
        }
        if (!array_key_exists('value', $value) && !array_key_exists('variant_key', $value)) {
            throw new ApiError('validation_failed', sprintf('rules[%d].serve requires value or variant_key', $index), 422);
        }

        $entry = [];
        if (array_key_exists('variant_key', $value)) {
            $variantKey = $this->nullableString($value['variant_key']);
            if ($variantKey === null) {
                throw new ApiError('validation_failed', sprintf('rules[%d].serve.variant_key must be a string', $index), 422);
            }
            $this->validator->validateVariants($flag['type'], $flag['options'], $flag['variants'], $variantKey);
            $entry['variant_key'] = $variantKey;
        }
        if (array_key_exists('value', $value)) {
            $this->validator->validateValue($flag['type'], $value['value'], $flag['options'], sprintf('rules[%d].serve.value', $index));
            $entry['value'] = $value['value'];
        }

        return $entry;
    }

    private function schedule(mixed $value, string $field): array
    {
        if (!is_array($value)) {
            throw new ApiError('validation_failed', sprintf('%s must be an object', $field), 422);
        }

        $normalized = [];
        foreach (['start_at', 'end_at'] as $key) {
            if (array_key_exists($key, $value)) {
                $normalized[$key] = $this->requireString($value, $key);
            }
        }

        return $normalized;
    }

    private function percentage(mixed $value, string $field): float
    {
        if (!is_numeric($value)) {
            throw new ApiError('validation_failed', sprintf('%s must be numeric', $field), 422);
        }
        $float = (float) $value;
        if ($float < 0 || $float > 100) {
            throw new ApiError('validation_failed', sprintf('%s must be between 0 and 100', $field), 422);
        }

        return $float;
    }

    private function stringArray(mixed $value, string $field): array
    {
        if (!is_array($value)) {
            throw new ApiError('validation_failed', sprintf('%s must be an array', $field), 422);
        }

        $normalized = [];
        foreach ($value as $index => $entry) {
            if (!is_string($entry) || trim($entry) === '') {
                throw new ApiError('validation_failed', sprintf('%s[%d] must be a non-empty string', $field, $index), 422);
            }
            $normalized[] = trim($entry);
        }

        return $normalized;
    }

    private function requireConditionString(array $condition, string $field, string $parent, int $index): string
    {
        $value = $condition[$field] ?? null;
        if (!is_string($value) || trim($value) === '') {
            throw new ApiError('validation_failed', sprintf('%s[%d].%s is required', $parent, $index, $field), 422);
        }

        return trim($value);
    }

    private function boolValue(mixed $value): bool
    {
        if (!is_bool($value)) {
            throw new ApiError('validation_failed', 'Value must be a boolean', 422);
        }

        return $value;
    }

    private function boolBody(array $payload, string $field, bool $default): bool
    {
        if (!array_key_exists($field, $payload)) {
            return $default;
        }

        return $this->boolValue($payload[$field]);
    }

    private function intValue(mixed $value, string $field, int $min, int $max): int
    {
        if (!is_int($value) && !(is_string($value) && preg_match('/^-?\d+$/', $value) === 1)) {
            throw new ApiError('validation_failed', sprintf('%s must be an integer', $field), 422);
        }
        $int = (int) $value;
        if ($int < $min || $int > $max) {
            throw new ApiError('validation_failed', sprintf('%s must be between %d and %d', $field, $min, $max), 422);
        }

        return $int;
    }

    private function flagKind(mixed $value): string
    {
        if (!is_string($value) || !in_array($value, ['release', 'experiment', 'ops', 'permission'], true)) {
            throw new ApiError('validation_failed', 'flag_kind must be release, experiment, ops, or permission', 422);
        }

        return $value;
    }

    private function identityKind(mixed $value): string
    {
        if (!is_string($value) || trim($value) === '') {
            throw new ApiError('validation_failed', 'kind is required', 422);
        }
        $value = trim($value);
        if (!preg_match('/^[a-z][a-z0-9_:-]{0,31}$/', $value)) {
            throw new ApiError('validation_failed', 'kind must be a lowercase identifier', 422);
        }

        return $value;
    }

    private function identityIdentifier(mixed $value): string
    {
        if (!is_string($value) || trim($value) === '') {
            throw new ApiError('validation_failed', 'identifier is required', 422);
        }

        return trim($value);
    }

    private function identityStatus(mixed $value): string
    {
        if (!is_string($value) || !in_array($value, ['active', 'disabled', 'deleted'], true)) {
            throw new ApiError('validation_failed', 'status must be active, disabled, or deleted', 422);
        }

        return $value;
    }

    private function transientTraitsPayload(array $payload): array
    {
        return $this->identityService->assertTraitPayload($payload['transient_traits'] ?? []);
    }

    private function databaseTimestamp(mixed $value): ?string
    {
        $timestamp = $this->nullableString($value);
        if ($timestamp === null) {
            return null;
        }

        $unix = strtotime($timestamp);
        if ($unix === false) {
            throw new ApiError('validation_failed', 'Invalid timestamp value', 422);
        }

        return gmdate('Y-m-d H:i:s', $unix);
    }

    private function changeRequestDesiredConfig(mixed $value): array
    {
        if (!is_array($value)) {
            throw new ApiError('validation_failed', 'desired_config is required', 422);
        }

        return [
            'default_value' => $value['default_value'] ?? null,
            'default_variant_key' => $this->nullableString($value['default_variant_key'] ?? null),
            'rules' => is_array($value['rules'] ?? null) ? $value['rules'] : [],
        ];
    }

    private function buildImportPlan(string $projectId, array $payload): array
    {
        $plan = [
            'creates' => [],
            'updates' => [],
            'conflicts' => [],
            'skipped' => [],
            'environments' => [],
            'segments' => [],
            'flags' => [],
            'flag_environment_configs' => [],
        ];

        $environments = $payload['environments'] ?? [];
        if (is_array($environments)) {
            usort($environments, fn (array $a, array $b): int => strcmp((string) ($a['key'] ?? ''), (string) ($b['key'] ?? '')));
            foreach ($environments as $entry) {
                $key = (string) ($entry['key'] ?? '');
                $exists = $key !== '' ? $this->environments->findByKey($projectId, $key) : null;
                $action = $exists === null ? 'create' : 'update';
                if ($exists !== null) {
                    $plan['conflicts'][] = ['resource' => 'environment', 'key' => $key];
                }
                $plan['environments'][] = [
                    'key' => $key,
                    'action' => $action,
                    'payload' => [
                        'key' => $key,
                        'name' => $entry['name'] ?? $key,
                        'description' => $entry['description'] ?? null,
                        'is_default' => (bool) ($entry['is_default'] ?? false),
                        'requires_change_requests' => (bool) ($entry['requires_change_requests'] ?? false),
                        'sort_order' => (int) ($entry['sort_order'] ?? 100),
                    ],
                ];
            }
        }

        $segments = $payload['segments'] ?? [];
        if (is_array($segments)) {
            usort($segments, fn (array $a, array $b): int => strcmp((string) ($a['key'] ?? ''), (string) ($b['key'] ?? '')));
            foreach ($segments as $entry) {
                $key = (string) ($entry['key'] ?? '');
                $exists = $key !== '' ? $this->segments->findByKey($projectId, $key) : null;
                if ($exists !== null) {
                    $plan['conflicts'][] = ['resource' => 'segment', 'key' => $key];
                }
                $plan['segments'][] = [
                    'key' => $key,
                    'action' => $exists === null ? 'create' : 'update',
                    'payload' => [
                        'key' => $key,
                        'name' => $entry['name'] ?? $key,
                        'description' => $entry['description'] ?? null,
                        'rules' => $entry['rules'] ?? [],
                    ],
                ];
            }
        }

        $flags = $payload['flags'] ?? [];
        if (is_array($flags)) {
            usort($flags, fn (array $a, array $b): int => strcmp((string) ($a['key'] ?? ''), (string) ($b['key'] ?? '')));
            foreach ($flags as $entry) {
                $key = (string) ($entry['key'] ?? '');
                $exists = $key !== '' ? $this->flags->findByKey($projectId, $key) : null;
                if ($exists !== null) {
                    $plan['conflicts'][] = ['resource' => 'flag', 'key' => $key];
                }
                $plan['flags'][] = [
                    'key' => $key,
                    'action' => $exists === null ? 'create' : 'update',
                    'payload' => [
                        'key' => $key,
                        'name' => $entry['name'] ?? $key,
                        'description' => $entry['description'] ?? null,
                        'flag_kind' => $entry['flag_kind'] ?? 'release',
                        'type' => $entry['type'] ?? 'boolean',
                        'default_value' => $entry['default_value'] ?? false,
                        'options' => $entry['options'] ?? null,
                        'variants' => $entry['variants'] ?? null,
                        'default_variant_key' => $entry['default_variant_key'] ?? null,
                        'prerequisites' => $entry['prerequisites'] ?? null,
                        'expires_at' => $entry['expires_at'] ?? null,
                    ],
                ];
            }
        }

        $configs = $payload['flag_environment_configs'] ?? [];
        if (is_array($configs)) {
            usort($configs, fn (array $a, array $b): int => [($a['environment_key'] ?? ''), ($a['flag_key'] ?? '')] <=> [($b['environment_key'] ?? ''), ($b['flag_key'] ?? '')]);
            foreach ($configs as $entry) {
                $plan['flag_environment_configs'][] = [
                    'flag_key' => $entry['flag_key'],
                    'environment_key' => $entry['environment_key'],
                    'payload' => [
                        'default_value' => $entry['default_value'] ?? null,
                        'default_variant_key' => $entry['default_variant_key'] ?? null,
                        'rules' => $entry['rules'] ?? [],
                    ],
                ];
            }
        }

        $plan['creates'] = count(array_filter([...$plan['environments'], ...$plan['segments'], ...$plan['flags']], fn (array $entry): bool => $entry['action'] === 'create'));
        $plan['updates'] = count(array_filter([...$plan['environments'], ...$plan['segments'], ...$plan['flags']], fn (array $entry): bool => $entry['action'] === 'update'));

        return $plan;
    }

    private function environmentFromRequest(string $projectId, ?string $environmentKey): array
    {
        if ($environmentKey !== null && $environmentKey !== '') {
            return $this->requireEnvironmentByKey($projectId, $environmentKey);
        }

        $environment = $this->resolvedConfig->defaultEnvironment($projectId);
        if ($environment === null) {
            throw new ApiError('not_found', 'No default environment exists for this project', 404);
        }

        return $environment;
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
