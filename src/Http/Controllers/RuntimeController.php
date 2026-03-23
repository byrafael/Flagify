<?php

declare(strict_types=1);

namespace Flagify\Http\Controllers;

use Flagify\Auth\ScopeAuthorizer;
use Flagify\Repository\ClientRepository;
use Flagify\Repository\ProjectRepository;
use Flagify\Service\ResolvedConfigService;
use Flagify\Support\ApiError;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class RuntimeController extends AbstractController
{
    public function __construct(
        private readonly ProjectRepository $projects,
        private readonly ClientRepository $clients,
        private readonly ResolvedConfigService $resolvedConfig,
        private readonly ScopeAuthorizer $authorizer,
        \Flagify\Support\Responder $responder
    ) {
        parent::__construct($responder);
    }

    public function currentClientConfig(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $principal = $this->authorizer->requireScope(
            $this->principal($request),
            ScopeAuthorizer::RUNTIME_READ,
            $this->principal($request)?->projectId,
            $this->principal($request)?->clientId
        );
        if ($principal->projectId === null || $principal->clientId === null) {
            throw new ApiError('forbidden', 'This key is not bound to a runtime client', 403);
        }

        $project = $this->projects->find($principal->projectId);
        $client = $this->clients->find($principal->projectId, $principal->clientId);
        if ($project === null || $client === null) {
            throw new ApiError('not_found', 'Project or client not found', 404);
        }
        if ($project['status'] !== 'active' || $client['status'] !== 'active') {
            throw new ApiError('unauthorized', 'Project or client is inactive', 401);
        }

        return $this->responder->json($this->resolvedPayload($principal->projectId, $project['slug'], $client));
    }

    public function currentClientFlag(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $principal = $this->authorizer->requireScope(
            $this->principal($request),
            ScopeAuthorizer::RUNTIME_READ,
            $this->principal($request)?->projectId,
            $this->principal($request)?->clientId
        );
        if ($principal->projectId === null || $principal->clientId === null) {
            throw new ApiError('forbidden', 'This key is not bound to a runtime client', 403);
        }

        $project = $this->projects->find($principal->projectId);
        $client = $this->clients->find($principal->projectId, $principal->clientId);
        if ($project === null || $client === null) {
            throw new ApiError('not_found', 'Project or client not found', 404);
        }
        if ($project['status'] !== 'active' || $client['status'] !== 'active') {
            throw new ApiError('unauthorized', 'Project or client is inactive', 401);
        }

        $payload = $this->resolvedPayload($principal->projectId, $project['slug'], $client);

        if (!array_key_exists($args['flagKey'], $payload['flags'])) {
            throw new ApiError('not_found', 'Flag not found', 404);
        }

        return $this->responder->json(['key' => $args['flagKey'], 'value' => $payload['flags'][$args['flagKey']]]);
    }

    public function projectClientConfig(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $projectId = $args['projectId'];
        $clientKey = $args['clientKey'];
        $this->authorizer->requireScope($this->principal($request), ScopeAuthorizer::RUNTIME_READ_ANY, $projectId);

        $project = $this->projects->find($projectId);
        $client = $this->clients->findByKey($projectId, $clientKey);
        if ($project === null || $client === null) {
            throw new ApiError('not_found', 'Project or client not found', 404);
        }
        if ($project['status'] !== 'active' || $client['status'] !== 'active') {
            throw new ApiError('unauthorized', 'Project or client is inactive', 401);
        }

        return $this->responder->json($this->resolvedPayload($projectId, $project['slug'], $client));
    }

    public function projectClientFlag(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $projectId = $args['projectId'];
        $clientKey = $args['clientKey'];
        $this->authorizer->requireScope($this->principal($request), ScopeAuthorizer::RUNTIME_READ_ANY, $projectId);

        $project = $this->projects->find($projectId);
        $client = $this->clients->findByKey($projectId, $clientKey);
        if ($project === null || $client === null) {
            throw new ApiError('not_found', 'Project or client not found', 404);
        }
        if ($project['status'] !== 'active' || $client['status'] !== 'active') {
            throw new ApiError('unauthorized', 'Project or client is inactive', 401);
        }

        $payload = $this->resolvedPayload($projectId, $project['slug'], $client);

        if (!array_key_exists($args['flagKey'], $payload['flags'])) {
            throw new ApiError('not_found', 'Flag not found', 404);
        }

        return $this->responder->json(['key' => $args['flagKey'], 'value' => $payload['flags'][$args['flagKey']]]);
    }

    private function resolvedPayload(string $projectId, string $projectSlug, array $client): array
    {
        $config = $this->resolvedConfig->resolveProjectClient($projectId, $client);
        $config['project']['slug'] = $projectSlug;

        return $config;
    }
}
