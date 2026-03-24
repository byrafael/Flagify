<?php

declare(strict_types=1);

namespace Flagify\Service;

use Flagify\Repository\ClientRepository;
use Flagify\Repository\IdentityRepository;
use Flagify\Repository\IdentityTraitRepository;
use Flagify\Support\ApiError;
use PDO;

final class IdentityService
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly IdentityRepository $identities,
        private readonly IdentityTraitRepository $traits,
        private readonly ClientRepository $clients
    ) {
    }

    public function create(string $projectId, array $payload): array
    {
        $traits = $payload['traits'] ?? [];

        $this->pdo->beginTransaction();
        try {
            $identity = $this->identities->create([
                'project_id' => $projectId,
                'kind' => $payload['kind'],
                'identifier' => $payload['identifier'],
                'display_name' => $payload['display_name'] ?? null,
                'description' => $payload['description'] ?? null,
                'client_id' => $payload['client_id'] ?? null,
            ]);

            $this->bulkPatchTraits($identity['id'], [
                'set' => $traits,
                'unset' => [],
            ]);

            $this->pdo->commit();

            return $this->get($projectId, $identity['id']);
        } catch (\Throwable $throwable) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $throwable;
        }
    }

    public function get(string $projectId, string $identityId): array
    {
        $identity = $this->identities->find($projectId, $identityId);
        if ($identity === null) {
            throw new ApiError('not_found', 'Identity not found', 404);
        }
        $identity['traits'] = $this->traitMap($identity['id']);

        return $identity;
    }

    public function ensureClientIdentity(string $projectId, array $client): array
    {
        $identity = $this->identities->findByClientId($projectId, $client['id']);
        if ($identity !== null) {
            return $identity;
        }

        return $this->identities->create([
            'project_id' => $projectId,
            'kind' => 'client',
            'identifier' => $client['key'],
            'display_name' => $client['name'],
            'description' => $client['description'] ?? null,
            'status' => $client['status'] ?? 'active',
            'client_id' => $client['id'],
            'deleted_at' => $client['deleted_at'] ?? null,
        ]);
    }

    public function syncClientIdentity(string $projectId, array $client): array
    {
        $identity = $this->ensureClientIdentity($projectId, $client);

        return $this->identities->update($projectId, $identity['id'], [
            'identifier' => $client['key'],
            'display_name' => $client['name'],
            'description' => $client['description'] ?? null,
            'status' => $client['status'] ?? 'active',
            'deleted_at' => $client['deleted_at'] ?? null,
        ]);
    }

    public function upsertTrait(string $identityId, string $traitKey, mixed $traitValue): array
    {
        $this->assertTraitValue($traitValue);

        return $this->traits->upsert($identityId, $traitKey, $traitValue, $this->valueType($traitValue));
    }

    public function bulkPatchTraits(string $identityId, array $payload): array
    {
        $set = $payload['set'] ?? [];
        $unset = $payload['unset'] ?? [];
        if (!is_array($set) || !is_array($unset)) {
            throw new ApiError('validation_failed', 'set and unset must be arrays', 422);
        }

        foreach ($set as $traitKey => $traitValue) {
            if (!is_string($traitKey) || trim($traitKey) === '') {
                throw new ApiError('validation_failed', 'trait keys must be non-empty strings', 422);
            }
            $this->upsertTrait($identityId, trim($traitKey), $traitValue);
        }

        foreach ($unset as $traitKey) {
            if (!is_string($traitKey) || trim($traitKey) === '') {
                throw new ApiError('validation_failed', 'unset trait keys must be non-empty strings', 422);
            }
            $this->traits->delete($identityId, trim($traitKey));
        }

        return $this->traitMap($identityId);
    }

    public function traitMap(string $identityId): array
    {
        $traits = [];
        foreach ($this->traits->allForIdentity($identityId) as $entry) {
            $traits[$entry['trait_key']] = $entry['trait_value'];
        }

        ksort($traits);

        return $traits;
    }

    public function persistedTraitsForIdentity(array $identity): array
    {
        return $this->traitMap($identity['id']);
    }

    public function effectiveTraits(array $identity, ?array $client, array $transientTraits = []): array
    {
        $persisted = $this->persistedTraitsForIdentity($identity);
        $base = $persisted;

        if ($client !== null) {
            foreach (($client['metadata'] ?? []) as $key => $value) {
                if (!array_key_exists($key, $base)) {
                    $base[$key] = $value;
                }
            }
        }

        foreach ($transientTraits as $key => $value) {
            $base[$key] = $value;
        }

        ksort($base);

        return $base;
    }

    public function assertTraitPayload(mixed $value): array
    {
        if (!is_array($value)) {
            throw new ApiError('validation_failed', 'traits must be a JSON object', 422);
        }
        foreach ($value as $traitKey => $traitValue) {
            if (!is_string($traitKey) || trim($traitKey) === '') {
                throw new ApiError('validation_failed', 'trait keys must be non-empty strings', 422);
            }
            $this->assertTraitValue($traitValue);
        }

        return $value;
    }

    private function assertTraitValue(mixed $value): void
    {
        if (is_array($value)) {
            if (!array_is_list($value)) {
                throw new ApiError('validation_failed', 'Nested object trait values are not supported', 422);
            }
            foreach ($value as $entry) {
                if (is_array($entry) || is_object($entry)) {
                    throw new ApiError('validation_failed', 'Nested object trait values are not supported', 422);
                }
            }

            return;
        }

        if (is_object($value)) {
            throw new ApiError('validation_failed', 'Nested object trait values are not supported', 422);
        }

        if (!is_scalar($value) && $value !== null) {
            throw new ApiError('validation_failed', 'Trait values must be scalar, null, or arrays', 422);
        }
    }

    private function valueType(mixed $value): string
    {
        return match (true) {
            is_array($value) => 'array',
            is_bool($value) => 'boolean',
            is_int($value) => 'integer',
            is_float($value) => 'float',
            $value === null => 'null',
            default => 'string',
        };
    }
}
