<?php

declare(strict_types=1);

namespace Flagify\Http\Controllers;

use Flagify\Auth\ScopeAuthorizer;
use Flagify\Repository\ClientRepository;
use Flagify\Repository\ProjectRepository;
use Flagify\Support\ApiError;
use Flagify\Support\Json;
use Flagify\Support\Request;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class ClientController extends AbstractController
{
    public function __construct(
        private readonly ClientRepository $clients,
        private readonly ProjectRepository $projects,
        private readonly ScopeAuthorizer $authorizer,
        \Flagify\Support\Responder $responder
    ) {
        parent::__construct($responder);
    }

    public function create(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $projectId = $args['projectId'];
        $this->authorizer->requireScope($this->principal($request), ScopeAuthorizer::CLIENTS_WRITE, $projectId);
        $this->requireProject($projectId);

        $payload = Request::json($request);
        $this->validateClient($payload, false);
        $client = $this->clients->create([...$payload, 'project_id' => $projectId]);

        return $this->responder->json($this->normalizeResource($client), 201);
    }

    public function index(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $projectId = $args['projectId'];
        $this->authorizer->requireScope($this->principal($request), ScopeAuthorizer::CLIENTS_READ, $projectId);
        $this->requireProject($projectId);
        $limit = Request::intQuery($request, 'limit', 50);
        $offset = Request::nonNegativeIntQuery($request, 'offset', 0, 1000000);

        return $this->responder->json($this->pagination(
            $this->clients->paginateByProject($projectId, $limit, $offset),
            $this->clients->countByProject($projectId),
            $limit,
            $offset
        ));
    }

    public function show(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $projectId = $args['projectId'];
        $this->authorizer->requireScope($this->principal($request), ScopeAuthorizer::CLIENTS_READ, $projectId);
        $client = $this->clients->find($projectId, $args['clientId']);
        if ($client === null) {
            throw new ApiError('not_found', 'Client not found', 404);
        }

        return $this->responder->json($this->normalizeResource($client));
    }

    public function update(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $projectId = $args['projectId'];
        $clientId = $args['clientId'];
        $this->authorizer->requireScope($this->principal($request), ScopeAuthorizer::CLIENTS_WRITE, $projectId);
        if ($this->clients->find($projectId, $clientId) === null) {
            throw new ApiError('not_found', 'Client not found', 404);
        }

        $payload = Request::json($request);
        $this->validateClient($payload, true);

        return $this->responder->json($this->normalizeResource($this->clients->update($projectId, $clientId, $payload)));
    }

    public function delete(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $projectId = $args['projectId'];
        $clientId = $args['clientId'];
        $this->authorizer->requireScope($this->principal($request), ScopeAuthorizer::CLIENTS_WRITE, $projectId);
        if ($this->clients->find($projectId, $clientId) === null) {
            throw new ApiError('not_found', 'Client not found', 404);
        }

        $this->clients->softDelete($projectId, $clientId);

        return $this->responder->noContent();
    }

    private function requireProject(string $projectId): void
    {
        $project = $this->projects->find($projectId);
        if ($project === null) {
            throw new ApiError('not_found', 'Project not found', 404);
        }
        if ($project['status'] === 'deleted') {
            throw new ApiError('resource_deleted', 'Project has been deleted', 410);
        }
    }

    private function validateClient(array $payload, bool $partial): void
    {
        foreach (['key', 'name'] as $required) {
            if (!$partial && !array_key_exists($required, $payload)) {
                throw new ApiError('validation_failed', sprintf('%s is required', $required), 422);
            }
        }

        if (array_key_exists('key', $payload) && !preg_match('/^[a-z0-9_-]+$/', (string) $payload['key'])) {
            throw new ApiError('validation_failed', 'key must contain only lowercase letters, numbers, underscores, and dashes', 422);
        }

        if (array_key_exists('status', $payload) && !in_array($payload['status'], ['active', 'disabled', 'deleted'], true)) {
            throw new ApiError('validation_failed', 'status must be active, disabled, or deleted', 422);
        }

        if (array_key_exists('metadata', $payload) && strlen(Json::encode($payload['metadata'])) > 16384) {
            throw new ApiError('validation_failed', 'metadata exceeds the 16KB limit', 422);
        }
    }
}
