<?php

declare(strict_types=1);

namespace Flagify\Http\Controllers;

use Flagify\Auth\ScopeAuthorizer;
use Flagify\Repository\ClientRepository;
use Flagify\Repository\FlagRepository;
use Flagify\Repository\OverrideRepository;
use Flagify\Repository\ProjectRepository;
use Flagify\Service\FlagValueValidator;
use Flagify\Support\ApiError;
use Flagify\Support\Request;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class OverrideController extends AbstractController
{
    public function __construct(
        private readonly ProjectRepository $projects,
        private readonly ClientRepository $clients,
        private readonly FlagRepository $flags,
        private readonly OverrideRepository $overrides,
        private readonly FlagValueValidator $validator,
        private readonly ScopeAuthorizer $authorizer,
        \Flagify\Support\Responder $responder
    ) {
        parent::__construct($responder);
    }

    public function put(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $projectId = $args['projectId'];
        $clientId = $args['clientId'];
        $flagId = $args['flagId'];
        $this->authorizer->requireScope($this->principal($request), ScopeAuthorizer::OVERRIDES_WRITE, $projectId);

        $client = $this->clients->find($projectId, $clientId);
        $flag = $this->flags->find($projectId, $flagId);
        if ($client === null || $flag === null || $this->projects->find($projectId) === null) {
            throw new ApiError('not_found', 'Project, client, or flag not found', 404);
        }
        if ($client['status'] !== 'active') {
            throw new ApiError('unsupported_operation', 'Client is inactive', 409);
        }
        if ($flag['status'] === 'archived') {
            throw new ApiError('unsupported_operation', 'Archived flags cannot receive overrides', 409);
        }

        $payload = Request::json($request);
        if (!array_key_exists('value', $payload)) {
            throw new ApiError('validation_failed', 'value is required', 422);
        }

        $this->validator->validateValue($flag['type'], $payload['value'], $flag['options'], 'value');
        $override = $this->overrides->upsert($projectId, $flagId, $clientId, $payload['value']);

        return $this->responder->json($this->normalizeResource($override), 200);
    }

    public function index(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $projectId = $args['projectId'];
        $clientId = $args['clientId'];
        $this->authorizer->requireScope($this->principal($request), ScopeAuthorizer::OVERRIDES_READ, $projectId);
        $client = $this->clients->find($projectId, $clientId);
        if ($client === null) {
            throw new ApiError('not_found', 'Client not found', 404);
        }
        if ($client['status'] === 'deleted') {
            throw new ApiError('resource_deleted', 'Client has been deleted', 410);
        }

        return $this->responder->json([
            'items' => array_map(
                fn (array $row) => $this->normalizeResource($row),
                $this->overrides->forClient($projectId, $clientId)
            ),
        ]);
    }

    public function delete(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $projectId = $args['projectId'];
        $clientId = $args['clientId'];
        $flagId = $args['flagId'];
        $this->authorizer->requireScope($this->principal($request), ScopeAuthorizer::OVERRIDES_WRITE, $projectId);

        $this->overrides->delete($flagId, $clientId);

        return $this->responder->noContent();
    }
}
