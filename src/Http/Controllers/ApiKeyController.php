<?php

declare(strict_types=1);

namespace Flagify\Http\Controllers;

use Flagify\Auth\ApiKeyGenerator;
use Flagify\Auth\ScopeAuthorizer;
use Flagify\Domain\KeyKind;
use Flagify\Repository\ApiKeyRepository;
use Flagify\Repository\ClientRepository;
use Flagify\Repository\ProjectRepository;
use Flagify\Support\ApiError;
use Flagify\Support\Request;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class ApiKeyController extends AbstractController
{
    public function __construct(
        private readonly ApiKeyRepository $apiKeys,
        private readonly ProjectRepository $projects,
        private readonly ClientRepository $clients,
        private readonly ApiKeyGenerator $generator,
        private readonly ScopeAuthorizer $authorizer,
        \Flagify\Support\Responder $responder
    ) {
        parent::__construct($responder);
    }

    public function create(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $principal = $this->principal($request);
        $this->authorizer->requireScope($principal, ScopeAuthorizer::KEYS_WRITE, $principal?->projectId);

        $payload = Request::json($request);
        foreach (['kind', 'name'] as $required) {
            if (!array_key_exists($required, $payload)) {
                throw new ApiError('validation_failed', sprintf('%s is required', $required), 422);
            }
        }

        $kind = $payload['kind'];
        if (!in_array($kind, [KeyKind::PROJECT_ADMIN, KeyKind::PROJECT_READ, KeyKind::CLIENT_RUNTIME], true)) {
            throw new ApiError('unsupported_operation', 'Only project-scoped and client runtime keys can be created', 422);
        }

        $projectId = $payload['project_id'] ?? $principal?->projectId;
        $project = is_string($projectId) ? $this->projects->find($projectId) : null;
        if (!is_string($projectId) || $project === null) {
            throw new ApiError('validation_failed', 'A valid project_id is required', 422);
        }
        if ($project['status'] !== 'active') {
            throw new ApiError('resource_deleted', 'Project has been deleted', 410);
        }

        if ($principal !== null && !$principal->isRoot() && $principal->projectId !== $projectId) {
            throw new ApiError('forbidden', 'API key cannot create keys for another project', 403);
        }

        $clientId = $payload['client_id'] ?? null;
        if ($kind === KeyKind::CLIENT_RUNTIME) {
            if (!is_string($clientId) || $this->clients->find($projectId, $clientId) === null) {
                throw new ApiError('validation_failed', 'A valid client_id is required for client runtime keys', 422);
            }
        } else {
            $clientId = null;
        }

        $scopes = $payload['scopes'] ?? $this->authorizer->defaultScopesForKind($kind);
        if (!is_array($scopes)) {
            throw new ApiError('validation_failed', 'scopes must be an array', 422);
        }
        $this->authorizer->assertScopesForKind($kind, $scopes);

        $secret = $this->generator->generate();
        $record = $this->apiKeys->create([
            'project_id' => $projectId,
            'client_id' => $clientId,
            'name' => $payload['name'],
            'prefix' => $secret['prefix'],
            'secret_hash' => $secret['secret_hash'],
            'kind' => $kind,
            'scopes' => array_values($scopes),
            'expires_at' => $payload['expires_at'] ?? null,
        ]);

        return $this->responder->json([
            ...$this->normalizeResource($record),
            'secret' => $secret['secret'],
        ], 201);
    }

    public function index(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $principal = $this->principal($request);
        $this->authorizer->requireScope($principal, ScopeAuthorizer::KEYS_READ, $principal?->projectId);
        $limit = Request::intQuery($request, 'limit', 50);
        $offset = Request::nonNegativeIntQuery($request, 'offset', 0, 1000000);
        $projectId = $principal !== null && !$principal->isRoot() ? $principal->projectId : ($request->getQueryParams()['project_id'] ?? null);

        return $this->responder->json($this->pagination(
            $this->apiKeys->paginate($limit, $offset, $projectId),
            $this->apiKeys->count($projectId),
            $limit,
            $offset
        ));
    }

    public function show(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $principal = $this->principal($request);
        $this->authorizer->requireScope($principal, ScopeAuthorizer::KEYS_READ, $principal?->projectId);
        $key = $this->apiKeys->find($args['keyId']);
        if ($key === null) {
            throw new ApiError('not_found', 'API key not found', 404);
        }

        if ($principal !== null && !$principal->isRoot() && $principal->projectId !== $key['project_id']) {
            throw new ApiError('forbidden', 'API key cannot access this key', 403);
        }

        return $this->responder->json($this->normalizeResource($key));
    }

    public function revoke(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $principal = $this->principal($request);
        $this->authorizer->requireScope($principal, ScopeAuthorizer::KEYS_WRITE, $principal?->projectId);
        $key = $this->apiKeys->find($args['keyId']);
        if ($key === null) {
            throw new ApiError('not_found', 'API key not found', 404);
        }

        if ($principal !== null && !$principal->isRoot() && $principal->projectId !== $key['project_id']) {
            throw new ApiError('forbidden', 'API key cannot revoke this key', 403);
        }

        $this->apiKeys->revoke($key['id']);

        return $this->responder->noContent();
    }
}
