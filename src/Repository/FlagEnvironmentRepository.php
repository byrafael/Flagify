<?php

declare(strict_types=1);

namespace Flagify\Repository;

use Flagify\Support\Clock;
use Flagify\Support\Json;
use Flagify\Support\Uuid;
use PDO;

class FlagEnvironmentRepository extends AbstractRepository
{
    public function __construct(PDO $pdo, private readonly Clock $clock)
    {
        parent::__construct($pdo);
    }

    public function createDefaultForFlag(string $flagId, string $environmentId, mixed $defaultValue, ?string $defaultVariantKey = null): array
    {
        return $this->upsert($flagId, $environmentId, [
            'default_value' => $defaultValue,
            'default_variant_key' => $defaultVariantKey,
            'rules' => [],
        ]);
    }

    public function upsert(string $flagId, string $environmentId, array $data): array
    {
        $existing = $this->find($flagId, $environmentId);
        if ($existing !== null) {
            $stmt = $this->pdo->prepare(
                'UPDATE flag_environment_configs
                 SET default_value = :default_value, default_variant_key = :default_variant_key, rules = :rules, updated_at = :updated_at
                 WHERE flag_id = :flag_id AND environment_id = :environment_id'
            );
            $stmt->execute([
                'flag_id' => $flagId,
                'environment_id' => $environmentId,
                'default_value' => $data['default_value'] === null ? null : Json::encode($data['default_value']),
                'default_variant_key' => $data['default_variant_key'] ?? null,
                'rules' => Json::encode($data['rules'] ?? []),
                'updated_at' => $this->clock->now(),
            ]);

            return $this->find($flagId, $environmentId);
        }

        $row = [
            'id' => Uuid::v7(),
            'flag_id' => $flagId,
            'environment_id' => $environmentId,
            'default_value' => $data['default_value'] ?? null,
            'default_variant_key' => $data['default_variant_key'] ?? null,
            'rules' => $data['rules'] ?? [],
            'created_at' => $this->clock->now(),
            'updated_at' => $this->clock->now(),
        ];

        $stmt = $this->pdo->prepare(
            'INSERT INTO flag_environment_configs (id, flag_id, environment_id, default_value, default_variant_key, rules, created_at, updated_at)
             VALUES (:id, :flag_id, :environment_id, :default_value, :default_variant_key, :rules, :created_at, :updated_at)'
        );
        $stmt->execute([
            ...$row,
            'default_value' => $row['default_value'] === null ? null : Json::encode($row['default_value']),
            'rules' => Json::encode($row['rules']),
        ]);

        return $row;
    }

    public function find(string $flagId, string $environmentId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM flag_environment_configs WHERE flag_id = :flag_id AND environment_id = :environment_id LIMIT 1'
        );
        $stmt->execute(['flag_id' => $flagId, 'environment_id' => $environmentId]);
        $row = $stmt->fetch();

        return $row ? $this->decodeJsonFields($row, ['default_value', 'rules']) : null;
    }

    public function findById(string $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM flag_environment_configs WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();

        return $row ? $this->decodeJsonFields($row, ['default_value', 'rules']) : null;
    }

    public function forFlag(string $flagId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM flag_environment_configs WHERE flag_id = :flag_id ORDER BY created_at ASC');
        $stmt->execute(['flag_id' => $flagId]);

        return array_map(fn (array $row) => $this->decodeJsonFields($row, ['default_value', 'rules']), $stmt->fetchAll());
    }

    public function forEnvironment(string $environmentId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM flag_environment_configs WHERE environment_id = :environment_id ORDER BY created_at ASC'
        );
        $stmt->execute(['environment_id' => $environmentId]);

        return array_map(fn (array $row) => $this->decodeJsonFields($row, ['default_value', 'rules']), $stmt->fetchAll());
    }

    public function delete(string $flagId, string $environmentId): void
    {
        $stmt = $this->pdo->prepare(
            'DELETE FROM flag_environment_configs WHERE flag_id = :flag_id AND environment_id = :environment_id'
        );
        $stmt->execute([
            'flag_id' => $flagId,
            'environment_id' => $environmentId,
        ]);
    }
}
