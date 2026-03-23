<?php

declare(strict_types=1);

namespace Flagify\Tests\Integration;

use Flagify\Tests\Support\ApiTestCase;

final class FlagifyApiTest extends ApiTestCase
{
    public function testBootstrapProjectCreateAndScopedKeyLifecycle(): void
    {
        [$status, $projectResponse] = $this->request('POST', '/api/v1/projects', $this->bootstrapKey, [
            'name' => 'Example',
            'slug' => 'example',
            'description' => 'Example project',
        ]);
        self::assertSame(201, $status);
        $projectId = $projectResponse['id'];

        [$status, $adminKeyResponse] = $this->request('POST', '/api/v1/keys', $this->bootstrapKey, [
            'kind' => 'project_admin',
            'name' => 'Project admin',
            'project_id' => $projectId,
        ]);
        self::assertSame(201, $status);
        $projectAdminSecret = $adminKeyResponse['secret'];

        [$status, $readKeyResponse] = $this->request('POST', '/api/v1/keys', $this->bootstrapKey, [
            'kind' => 'project_read',
            'name' => 'Project read',
            'project_id' => $projectId,
        ]);
        self::assertSame(201, $status);
        $projectReadSecret = $readKeyResponse['secret'];

        [$status, $flagResponse] = $this->request('POST', sprintf('/api/v1/projects/%s/flags', $projectId), $projectAdminSecret, [
            'key' => 'theme',
            'name' => 'Theme',
            'type' => 'select',
            'default_value' => 'dark',
            'options' => ['dark', 'light'],
        ]);
        self::assertSame(201, $status);
        $flagId = $flagResponse['id'];

        [$status, $multiFlagResponse] = $this->request('POST', sprintf('/api/v1/projects/%s/flags', $projectId), $projectAdminSecret, [
            'key' => 'allowed_regions',
            'name' => 'Allowed regions',
            'type' => 'multi_select',
            'default_value' => ['us'],
            'options' => ['us', 'ca', 'uk'],
        ]);
        self::assertSame(201, $status);

        [$status, $clientResponse] = $this->request('POST', sprintf('/api/v1/projects/%s/clients', $projectId), $projectAdminSecret, [
            'key' => 'ios-app',
            'name' => 'iOS App',
            'metadata' => ['platform' => 'ios'],
        ]);
        self::assertSame(201, $status);
        $clientId = $clientResponse['id'];

        [$status, $runtimeKeyResponse] = $this->request('POST', '/api/v1/keys', $projectAdminSecret, [
            'kind' => 'client_runtime',
            'name' => 'iOS runtime',
            'project_id' => $projectId,
            'client_id' => $clientId,
        ]);
        self::assertSame(201, $status);
        $runtimeSecret = $runtimeKeyResponse['secret'];

        [$status] = $this->request('PUT', sprintf('/api/v1/projects/%s/clients/%s/flags/%s/override', $projectId, $clientId, $flagId), $projectAdminSecret, [
            'value' => 'light',
        ]);
        self::assertSame(200, $status);

        [$status, $runtimeConfig] = $this->request('GET', '/api/v1/runtime/config', $runtimeSecret);
        self::assertSame(200, $status);
        self::assertSame('light', $runtimeConfig['flags']['theme']);
        self::assertSame(['us'], $runtimeConfig['flags']['allowed_regions']);

        [$status, $projectReadConfig] = $this->request('GET', sprintf('/api/v1/runtime/projects/%s/clients/%s/config', $projectId, 'ios-app'), $projectReadSecret);
        self::assertSame(200, $status);
        self::assertSame('light', $projectReadConfig['flags']['theme']);
    }

    public function testReadOnlyRuntimeDeletionRevocationAndValidationFailures(): void
    {
        [$status, $projectResponse] = $this->request('POST', '/api/v1/projects', $this->bootstrapKey, [
            'name' => 'Example',
            'slug' => 'example-2',
        ]);
        self::assertSame(201, $status);
        $projectId = $projectResponse['id'];

        [$status, $adminKeyResponse] = $this->request('POST', '/api/v1/keys', $this->bootstrapKey, [
            'kind' => 'project_admin',
            'name' => 'Project admin',
            'project_id' => $projectId,
        ]);
        self::assertSame(201, $status);
        $projectAdminSecret = $adminKeyResponse['secret'];

        [$status, $readKeyResponse] = $this->request('POST', '/api/v1/keys', $this->bootstrapKey, [
            'kind' => 'project_read',
            'name' => 'Project read',
            'project_id' => $projectId,
            'scopes' => ['project:read', 'runtime:read'],
        ]);
        self::assertSame(422, $status);

        [$status, $readKeyResponse] = $this->request('POST', '/api/v1/keys', $this->bootstrapKey, [
            'kind' => 'project_read',
            'name' => 'Project read',
            'project_id' => $projectId,
            'scopes' => ['projects:read', 'flags:read', 'clients:read', 'overrides:read', 'runtime:read_any'],
        ]);
        self::assertSame(201, $status);
        $projectReadSecret = $readKeyResponse['secret'];
        $readKeyId = $readKeyResponse['id'];

        [$status, $flagResponse] = $this->request('POST', sprintf('/api/v1/projects/%s/flags', $projectId), $projectAdminSecret, [
            'key' => 'new_dashboard',
            'name' => 'New dashboard',
            'type' => 'boolean',
            'default_value' => 'yes',
        ]);
        self::assertSame(422, $status);

        [$status, $flagResponse] = $this->request('POST', sprintf('/api/v1/projects/%s/flags', $projectId), $projectAdminSecret, [
            'key' => 'theme',
            'name' => 'Theme',
            'type' => 'select',
            'default_value' => 'dark',
            'options' => ['dark', 'light'],
        ]);
        self::assertSame(201, $status);
        $flagId = $flagResponse['id'];

        [$status, $clientResponse] = $this->request('POST', sprintf('/api/v1/projects/%s/clients', $projectId), $projectAdminSecret, [
            'key' => 'android-app',
            'name' => 'Android App',
        ]);
        self::assertSame(201, $status);
        $clientId = $clientResponse['id'];

        [$status, $runtimeKeyResponse] = $this->request('POST', '/api/v1/keys', $projectAdminSecret, [
            'kind' => 'client_runtime',
            'name' => 'Android runtime',
            'project_id' => $projectId,
            'client_id' => $clientId,
            'scopes' => ['runtime:read'],
        ]);
        self::assertSame(201, $status);
        $runtimeSecret = $runtimeKeyResponse['secret'];

        [$status] = $this->request('PUT', sprintf('/api/v1/projects/%s/clients/%s/flags/%s/override', $projectId, $clientId, $flagId), $projectAdminSecret, [
            'value' => 'light',
        ]);
        self::assertSame(200, $status);

        [$status, $response] = $this->request('PATCH', sprintf('/api/v1/projects/%s/flags/%s', $projectId, $flagId), $projectAdminSecret, [
            'options' => ['dark'],
        ]);
        self::assertSame(422, $status);
        self::assertSame('validation_failed', $response['error']['code']);

        [$status] = $this->request('GET', sprintf('/api/v1/projects/%s/clients', $projectId), $projectReadSecret);
        self::assertSame(200, $status);

        [$status, $response] = $this->request('POST', sprintf('/api/v1/projects/%s/clients', $projectId), $projectReadSecret, [
            'key' => 'web-app',
            'name' => 'Web App',
        ]);
        self::assertSame(403, $status);
        self::assertSame('forbidden', $response['error']['code']);

        [$status] = $this->request('PATCH', sprintf('/api/v1/projects/%s/clients/%s', $projectId, $clientId), $projectAdminSecret, [
            'status' => 'deleted',
        ]);
        self::assertSame(200, $status);

        [$status, $response] = $this->request('GET', '/api/v1/runtime/config', $runtimeSecret);
        self::assertSame(401, $status);
        self::assertSame('unauthorized', $response['error']['code']);

        [$status] = $this->request('POST', sprintf('/api/v1/keys/%s/revoke', $readKeyId), $projectAdminSecret);
        self::assertSame(204, $status);

        [$status, $response] = $this->request('GET', sprintf('/api/v1/runtime/projects/%s/clients/%s/config', $projectId, 'android-app'), $projectReadSecret);
        self::assertSame(401, $status);
        self::assertSame('unauthorized', $response['error']['code']);

        [$status] = $this->request('DELETE', sprintf('/api/v1/projects/%s', $projectId), $this->bootstrapKey);
        self::assertSame(204, $status);

        [$status, $response] = $this->request('GET', sprintf('/api/v1/projects/%s/flags', $projectId), $projectAdminSecret);
        self::assertSame(401, $status);
        self::assertSame('unauthorized', $response['error']['code']);
    }
}
