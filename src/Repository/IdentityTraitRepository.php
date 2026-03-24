<?php

declare(strict_types=1);

namespace Flagify\Repository;

use Flagify\Support\Clock;
use Flagify\Support\Json;
use Flagify\Support\Uuid;
use PDO;

class IdentityTraitRepository extends AbstractRepository
{
    public function __construct(PDO $pdo, private readonly Clock $clock)
    {
        parent::__construct($pdo);
    }

    public function allForIdentity(string $identityId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM identity_traits WHERE identity_id = :identity_id ORDER BY trait_key ASC');
        $stmt->execute(['identity_id' => $identityId]);

        return array_map(fn (array $row) => $this->decodeJsonFields($row, ['trait_value']), $stmt->fetchAll());
    }

    public function find(string $identityId, string $traitKey): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM identity_traits WHERE identity_id = :identity_id AND trait_key = :trait_key LIMIT 1'
        );
        $stmt->execute(['identity_id' => $identityId, 'trait_key' => $traitKey]);
        $row = $stmt->fetch();

        return $row ? $this->decodeJsonFields($row, ['trait_value']) : null;
    }

    public function upsert(string $identityId, string $traitKey, mixed $traitValue, string $valueType): array
    {
        $existing = $this->find($identityId, $traitKey);
        if ($existing !== null) {
            $stmt = $this->pdo->prepare(
                'UPDATE identity_traits SET trait_value = :trait_value, value_type = :value_type, updated_at = :updated_at
                 WHERE identity_id = :identity_id AND trait_key = :trait_key'
            );
            $stmt->execute([
                'identity_id' => $identityId,
                'trait_key' => $traitKey,
                'trait_value' => Json::encode($traitValue),
                'value_type' => $valueType,
                'updated_at' => $this->clock->now(),
            ]);

            return $this->find($identityId, $traitKey);
        }

        $row = [
            'id' => Uuid::v7(),
            'identity_id' => $identityId,
            'trait_key' => $traitKey,
            'trait_value' => $traitValue,
            'value_type' => $valueType,
            'created_at' => $this->clock->now(),
            'updated_at' => $this->clock->now(),
        ];

        $stmt = $this->pdo->prepare(
            'INSERT INTO identity_traits (id, identity_id, trait_key, trait_value, value_type, created_at, updated_at)
             VALUES (:id, :identity_id, :trait_key, :trait_value, :value_type, :created_at, :updated_at)'
        );
        $stmt->execute([
            ...$row,
            'trait_value' => Json::encode($traitValue),
        ]);

        return $row;
    }

    public function delete(string $identityId, string $traitKey): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM identity_traits WHERE identity_id = :identity_id AND trait_key = :trait_key');
        $stmt->execute(['identity_id' => $identityId, 'trait_key' => $traitKey]);
    }
}
