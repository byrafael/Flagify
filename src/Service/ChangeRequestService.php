<?php

declare(strict_types=1);

namespace Flagify\Service;

use Flagify\Auth\ApiPrincipal;
use Flagify\Repository\ChangeRequestRepository;
use Flagify\Repository\EnvironmentRepository;
use Flagify\Repository\FlagEnvironmentRepository;
use Flagify\Repository\FlagRepository;
use Flagify\Support\ApiError;

final class ChangeRequestService
{
    public function __construct(
        private readonly ChangeRequestRepository $requests,
        private readonly EnvironmentRepository $environments,
        private readonly FlagRepository $flags,
        private readonly FlagEnvironmentRepository $flagEnvironments,
        private readonly AuditLogService $auditLogs
    ) {
    }

    public function propose(ApiPrincipal $principal, string $projectId, array $payload): array
    {
        $environment = $this->environments->findByKey($projectId, $payload['environment_key']);
        if ($environment === null) {
            throw new ApiError('not_found', 'Environment not found', 404);
        }

        $flag = $this->flags->find($projectId, $payload['flag_id']);
        if ($flag === null) {
            throw new ApiError('not_found', 'Flag not found', 404);
        }

        $request = $this->requests->create([
            'project_id' => $projectId,
            'environment_id' => $environment['id'],
            'resource_type' => 'flag_environment_config',
            'resource_id' => $payload['flag_id'],
            'title' => $payload['title'],
            'description' => $payload['description'] ?? null,
            'proposed_by_principal_id' => $principal->keyId,
            'proposed_payload' => [
                'flag_id' => $payload['flag_id'],
                'environment_key' => $payload['environment_key'],
                'desired_config' => $payload['desired_config'],
            ],
            'base_snapshot_checksum' => $payload['base_snapshot_checksum'] ?? null,
        ]);

        $this->auditLogs->record($principal, [
            'project_id' => $projectId,
            'environment_id' => $environment['id'],
            'resource_type' => 'change_request',
            'resource_id' => $request['id'],
            'resource_key' => $payload['title'],
            'action' => 'created',
            'after_payload' => $request,
        ]);

        return $request;
    }

    public function review(ApiPrincipal $principal, string $projectId, string $changeRequestId, string $decision): array
    {
        $request = $this->require($projectId, $changeRequestId);
        if ($request['status'] !== 'pending') {
            throw new ApiError('unsupported_operation', 'Only pending change requests may be reviewed', 409);
        }

        $status = $decision === 'approve' ? 'approved' : 'rejected';
        $updated = $this->requests->update($projectId, $changeRequestId, [
            'status' => $status,
            'reviewed_by_principal_id' => $principal->keyId,
            'approved_payload' => $decision === 'approve' ? $request['proposed_payload'] : null,
        ]);

        $this->auditLogs->record($principal, [
            'project_id' => $projectId,
            'environment_id' => $updated['environment_id'],
            'resource_type' => 'change_request',
            'resource_id' => $updated['id'],
            'resource_key' => $updated['title'],
            'action' => $decision === 'approve' ? 'approved' : 'rejected',
            'before_payload' => $request,
            'after_payload' => $updated,
        ]);

        return $updated;
    }

    public function apply(ApiPrincipal $principal, string $projectId, string $changeRequestId, string $currentChecksum): array
    {
        $request = $this->require($projectId, $changeRequestId);
        if ($request['status'] === 'applied') {
            return $request;
        }
        if ($request['status'] !== 'approved') {
            throw new ApiError('unsupported_operation', 'Only approved change requests may be applied', 409);
        }
        if (($request['base_snapshot_checksum'] ?? null) !== null && $request['base_snapshot_checksum'] !== $currentChecksum) {
            throw new ApiError('conflict', 'Live config drifted since proposal', 409);
        }

        $approved = $request['approved_payload'] ?? $request['proposed_payload'];
        $environment = $this->environments->findByKey($projectId, $approved['environment_key']);
        $flag = $this->flags->find($projectId, $approved['flag_id']);
        if ($environment === null || $flag === null) {
            throw new ApiError('not_found', 'Referenced flag or environment no longer exists', 404);
        }

        $before = $this->flagEnvironments->find($flag['id'], $environment['id']);
        $appliedConfig = $this->flagEnvironments->upsert($flag['id'], $environment['id'], $approved['desired_config']);
        $updated = $this->requests->update($projectId, $changeRequestId, [
            'status' => 'applied',
            'applied_by_principal_id' => $principal->keyId,
            'applied_at' => gmdate('Y-m-d H:i:s'),
        ]);

        $this->auditLogs->record($principal, [
            'project_id' => $projectId,
            'environment_id' => $environment['id'],
            'resource_type' => 'change_request',
            'resource_id' => $updated['id'],
            'resource_key' => $updated['title'],
            'action' => 'applied',
            'before_payload' => $request,
            'after_payload' => $updated,
        ]);
        $this->auditLogs->record($principal, [
            'project_id' => $projectId,
            'environment_id' => $environment['id'],
            'resource_type' => 'flag_environment_config',
            'resource_id' => $appliedConfig['id'] ?? ($before['id'] ?? $flag['id']),
            'resource_key' => $flag['key'],
            'action' => 'applied',
            'before_payload' => $before,
            'after_payload' => $appliedConfig,
        ]);

        return $updated;
    }

    public function require(string $projectId, string $changeRequestId): array
    {
        $request = $this->requests->find($projectId, $changeRequestId);
        if ($request === null) {
            throw new ApiError('not_found', 'Change request not found', 404);
        }

        return $request;
    }
}
