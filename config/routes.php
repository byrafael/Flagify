<?php

declare(strict_types=1);

use Flagify\Http\Controllers\ApiKeyController;
use Flagify\Http\Controllers\ClientController;
use Flagify\Http\Controllers\FlagController;
use Flagify\Http\Controllers\OverrideController;
use Flagify\Http\Controllers\ProjectController;
use Flagify\Http\Controllers\RuntimeController;
use Slim\App;
use Slim\Routing\RouteCollectorProxy;

return static function (
    App $app,
    ProjectController $projects,
    FlagController $flags,
    ClientController $clients,
    OverrideController $overrides,
    ApiKeyController $keys,
    RuntimeController $runtime
): void {
    $app->group('/api/v1', function (RouteCollectorProxy $group) use (
        $projects,
        $flags,
        $clients,
        $overrides,
        $keys,
        $runtime
    ): void {
        $group->post('/projects', [$projects, 'create']);
        $group->get('/projects', [$projects, 'index']);
        $group->get('/projects/{projectId}', [$projects, 'show']);
        $group->patch('/projects/{projectId}', [$projects, 'update']);
        $group->delete('/projects/{projectId}', [$projects, 'delete']);

        $group->post('/projects/{projectId}/flags', [$flags, 'create']);
        $group->get('/projects/{projectId}/flags', [$flags, 'index']);
        $group->get('/projects/{projectId}/flags/{flagId}', [$flags, 'show']);
        $group->patch('/projects/{projectId}/flags/{flagId}', [$flags, 'update']);
        $group->delete('/projects/{projectId}/flags/{flagId}', [$flags, 'delete']);

        $group->post('/projects/{projectId}/clients', [$clients, 'create']);
        $group->get('/projects/{projectId}/clients', [$clients, 'index']);
        $group->get('/projects/{projectId}/clients/{clientId}', [$clients, 'show']);
        $group->patch('/projects/{projectId}/clients/{clientId}', [$clients, 'update']);
        $group->delete('/projects/{projectId}/clients/{clientId}', [$clients, 'delete']);

        $group->put('/projects/{projectId}/clients/{clientId}/flags/{flagId}/override', [$overrides, 'put']);
        $group->get('/projects/{projectId}/clients/{clientId}/overrides', [$overrides, 'index']);
        $group->delete('/projects/{projectId}/clients/{clientId}/flags/{flagId}/override', [$overrides, 'delete']);

        $group->post('/keys', [$keys, 'create']);
        $group->get('/keys', [$keys, 'index']);
        $group->get('/keys/{keyId}', [$keys, 'show']);
        $group->post('/keys/{keyId}/revoke', [$keys, 'revoke']);

        $group->get('/runtime/config', [$runtime, 'currentClientConfig']);
        $group->get('/runtime/config/{flagKey}', [$runtime, 'currentClientFlag']);
        $group->get('/runtime/projects/{projectId}/clients/{clientKey}/config', [$runtime, 'projectClientConfig']);
        $group->get('/runtime/projects/{projectId}/clients/{clientKey}/config/{flagKey}', [$runtime, 'projectClientFlag']);
    });
};
