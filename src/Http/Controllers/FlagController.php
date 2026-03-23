<?php

declare(strict_types=1);

namespace Flagify\Http\Controllers;

use Flagify\Auth\ScopeAuthorizer;
use Flagify\Repository\FlagRepository;
use Flagify\Repository\OverrideRepository;
use Flagify\Repository\ProjectRepository;
use Flagify\Service\FlagValueValidator;
use Flagify\Support\ApiError;
use Flagify\Support\Request;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class FlagController extends AbstractController
{
    public function __construct(
        private readonly FlagRepository $flags,
        private readonly ProjectRepository $projects,
        private readonly OverrideRepository $overrides,
        private readonly FlagValueValidator $validator,
        private readonly ScopeAuthorizer $authorizer,
        \Flagify\Support\Responder $responder
    ) {
        parent::__construct($responder);
    }

    public function create(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $projectId = $args['projectId'];
        $this->authorizer->requireScope($this->principal($request), ScopeAuthorizer::FLAGS_WRITE, $projectId);
        $this->requireProject($projectId);

        $payload = Request::json($request);
        $this->validatePayload($payload, false);
        $this->validator->validateFlag($payload['type'], $payload['default_value'], $payload['options'] ?? null);

        $flag = $this->flags->create([...$payload, 'project_id' => $projectId]);

        return $this->responder->json($this->normalizeResource($flag), 201);
    }

    public function index(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $projectId = $args['projectId'];
        $this->authorizer->requireScope($this->principal($request), ScopeAuthorizer::FLAGS_READ, $projectId);
        $this->requireProject($projectId);

        $limit = Request::intQuery($request, 'limit', 50);
        $offset = Request::nonNegativeIntQuery($request, 'offset', 0, 1000000);
        $includeArchived = Request::boolQuery($request, 'include_archived');

        return $this->responder->json($this->pagination(
            $this->flags->paginateByProject($projectId, $limit, $offset, $includeArchived),
            $this->flags->countByProject($projectId, $includeArchived),
            $limit,
            $offset
        ));
    }

    public function show(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $projectId = $args['projectId'];
        $this->authorizer->requireScope($this->principal($request), ScopeAuthorizer::FLAGS_READ, $projectId);
        $flag = $this->flags->find($projectId, $args['flagId']);
        if ($flag === null) {
            throw new ApiError('not_found', 'Flag not found', 404);
        }

        return $this->responder->json($this->normalizeResource($flag));
    }

    public function update(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $projectId = $args['projectId'];
        $flagId = $args['flagId'];
        $this->authorizer->requireScope($this->principal($request), ScopeAuthorizer::FLAGS_WRITE, $projectId);
        $flag = $this->flags->find($projectId, $flagId);
        if ($flag === null) {
            throw new ApiError('not_found', 'Flag not found', 404);
        }

        $payload = Request::json($request);
        $this->validatePayload($payload, true);
        if (array_key_exists('type', $payload) && $payload['type'] !== $flag['type']) {
            throw new ApiError('unsupported_operation', 'Flag type cannot be changed', 422);
        }

        $candidate = [
            'type' => $flag['type'],
            'default_value' => $payload['default_value'] ?? $flag['default_value'],
            'options' => array_key_exists('options', $payload) ? $payload['options'] : $flag['options'],
        ];

        $this->validator->validateFlag($candidate['type'], $candidate['default_value'], $candidate['options']);
        foreach ($this->overrides->valuesForFlag($flagId) as $overrideValue) {
            $this->validator->validateValue($candidate['type'], $overrideValue, $candidate['options'], 'override');
        }

        return $this->responder->json($this->normalizeResource($this->flags->update($projectId, $flagId, $payload)));
    }

    public function delete(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $projectId = $args['projectId'];
        $flagId = $args['flagId'];
        $this->authorizer->requireScope($this->principal($request), ScopeAuthorizer::FLAGS_WRITE, $projectId);
        $flag = $this->flags->find($projectId, $flagId);
        if ($flag === null) {
            throw new ApiError('not_found', 'Flag not found', 404);
        }

        if ($this->overrides->hasAnyForFlag($flagId)) {
            $this->flags->update($projectId, $flagId, ['status' => 'archived']);
        } else {
            $this->flags->delete($projectId, $flagId);
        }

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

    private function validatePayload(array $payload, bool $partial): void
    {
        foreach (['key', 'name', 'type', 'default_value'] as $required) {
            if (!$partial && !array_key_exists($required, $payload)) {
                throw new ApiError('validation_failed', sprintf('%s is required', $required), 422);
            }
        }

        if (array_key_exists('key', $payload) && !preg_match('/^[a-z0-9_-]+$/', (string) $payload['key'])) {
            throw new ApiError('validation_failed', 'key must contain only lowercase letters, numbers, underscores, and dashes', 422);
        }

        if (array_key_exists('status', $payload) && !in_array($payload['status'], ['active', 'archived'], true)) {
            throw new ApiError('validation_failed', 'status must be active or archived', 422);
        }
    }
}
