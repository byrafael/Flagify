<?php

declare(strict_types=1);

namespace Flagify\Http\Controllers;

use Flagify\Auth\ScopeAuthorizer;
use Flagify\Repository\ProjectRepository;
use Flagify\Support\ApiError;
use Flagify\Support\Request;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class ProjectController extends AbstractController
{
    public function __construct(
        private readonly ProjectRepository $projects,
        private readonly ScopeAuthorizer $authorizer,
        \Flagify\Support\Responder $responder
    ) {
        parent::__construct($responder);
    }

    public function create(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $principal = $this->principal($request);
        if ($principal === null) {
            throw new ApiError('unauthorized', 'Authentication is required', 401);
        }
        if (!$principal->isRoot()) {
            throw new ApiError('forbidden', 'Only root can create projects', 403);
        }
        $payload = Request::json($request);
        $this->validateProject($payload, false);

        $project = $this->projects->create($payload);

        return $this->responder->json($this->normalizeResource($project), 201);
    }

    public function index(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $principal = $this->principal($request);
        $this->authorizer->requireScope($principal, ScopeAuthorizer::PROJECTS_READ, $principal?->projectId);
        $limit = Request::intQuery($request, 'limit', 50);
        $offset = Request::nonNegativeIntQuery($request, 'offset', 0, 1000000);

        if ($principal !== null && !$principal->isRoot() && $principal->projectId !== null) {
            $project = $this->projects->find($principal->projectId);
            if ($project === null) {
                throw new ApiError('not_found', 'Project not found', 404);
            }

            return $this->responder->json($this->pagination([$project], 1, $limit, $offset));
        }

        return $this->responder->json(
            $this->pagination($this->projects->paginate($limit, $offset), $this->projects->count(), $limit, $offset)
        );
    }

    public function show(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $projectId = $args['projectId'];
        $this->authorizer->requireScope($this->principal($request), ScopeAuthorizer::PROJECTS_READ, $projectId);
        $project = $this->projects->find($projectId);
        if ($project === null) {
            throw new ApiError('not_found', 'Project not found', 404);
        }
        if ($project['status'] === 'deleted') {
            throw new ApiError('resource_deleted', 'Project has been deleted', 410);
        }

        return $this->responder->json($this->normalizeResource($project));
    }

    public function update(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $principal = $this->principal($request);
        if ($principal === null) {
            throw new ApiError('unauthorized', 'Authentication is required', 401);
        }
        if (!$principal->isRoot()) {
            throw new ApiError('forbidden', 'Only root can update projects', 403);
        }
        $projectId = $args['projectId'];
        $project = $this->projects->find($projectId);
        if ($project === null) {
            throw new ApiError('not_found', 'Project not found', 404);
        }
        if ($project['status'] === 'deleted') {
            throw new ApiError('resource_deleted', 'Project has been deleted', 410);
        }

        $payload = Request::json($request);
        $this->validateProject($payload, true);

        return $this->responder->json($this->normalizeResource($this->projects->update($projectId, $payload)));
    }

    public function delete(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $principal = $this->principal($request);
        if ($principal === null) {
            throw new ApiError('unauthorized', 'Authentication is required', 401);
        }
        if (!$principal->isRoot()) {
            throw new ApiError('forbidden', 'Only root can delete projects', 403);
        }
        $projectId = $args['projectId'];
        if ($this->projects->find($projectId) === null) {
            throw new ApiError('not_found', 'Project not found', 404);
        }

        $this->projects->softDelete($projectId);

        return $this->responder->noContent();
    }

    private function validateProject(array $payload, bool $partial): void
    {
        foreach (['name', 'slug'] as $required) {
            if (!$partial && !array_key_exists($required, $payload)) {
                throw new ApiError('validation_failed', sprintf('%s is required', $required), 422);
            }
        }

        if (array_key_exists('slug', $payload) && !preg_match('/^[a-z0-9-]+$/', (string) $payload['slug'])) {
            throw new ApiError('validation_failed', 'slug must contain only lowercase letters, numbers, and dashes', 422);
        }
    }
}
