<?php

declare(strict_types=1);

namespace Flagify\Repository;

use Flagify\Support\Clock;
use Flagify\Support\Json;
use Flagify\Support\Uuid;
use PDO;

class OverrideRepository extends AbstractRepository
{
    public function __construct(PDO $pdo, private readonly Clock $clock)
    {
        parent::__construct($pdo);
    }

    public function upsert(string $projectId, string $flagId, string $clientId, mixed $value): array
    {
        $existing = $this->find($flagId, $clientId);
        if ($existing !== null) {
            $stmt = $this->pdo->prepare(
                'UPDATE flag_overrides SET value = :value, updated_at = :updated_at WHERE flag_id = :flag_id AND client_id = :client_id'
            );
            $stmt->execute([
                'value' => Json::encode($value),
                'updated_at' => $this->clock->now(),
                'flag_id' => $flagId,
                'client_id' => $clientId,
            ]);

            return $this->find($flagId, $clientId);
        }

        $row = [
            'id' => Uuid::v7(),
            'project_id' => $projectId,
            'flag_id' => $flagId,
            'client_id' => $clientId,
            'value' => $value,
            'created_at' => $this->clock->now(),
            'updated_at' => $this->clock->now(),
        ];

        $stmt = $this->pdo->prepare(
            'INSERT INTO flag_overrides (id, project_id, flag_id, client_id, value, created_at, updated_at)
             VALUES (:id, :project_id, :flag_id, :client_id, :value, :created_at, :updated_at)'
        );
        $stmt->execute([
            ...$row,
            'value' => Json::encode($value),
        ]);

        return $row;
    }

    public function find(string $flagId, string $clientId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM flag_overrides WHERE flag_id = :flag_id AND client_id = :client_id LIMIT 1');
        $stmt->execute(['flag_id' => $flagId, 'client_id' => $clientId]);
        $row = $stmt->fetch();

        return $row ? $this->decodeJsonFields($row, ['value']) : null;
    }

    public function forClient(string $projectId, string $clientId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM flag_overrides WHERE project_id = :project_id AND client_id = :client_id');
        $stmt->execute(['project_id' => $projectId, 'client_id' => $clientId]);

        return array_map(fn (array $row) => $this->decodeJsonFields($row, ['value']), $stmt->fetchAll());
    }

    public function valuesForFlag(string $flagId): array
    {
        $stmt = $this->pdo->prepare('SELECT value FROM flag_overrides WHERE flag_id = :flag_id');
        $stmt->execute(['flag_id' => $flagId]);

        return array_map(
            static fn (array $row) => Json::decode($row['value']),
            $stmt->fetchAll()
        );
    }

    public function hasAnyForFlag(string $flagId): bool
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM flag_overrides WHERE flag_id = :flag_id');
        $stmt->execute(['flag_id' => $flagId]);

        return (int) $stmt->fetchColumn() > 0;
    }

    public function delete(string $flagId, string $clientId): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM flag_overrides WHERE flag_id = :flag_id AND client_id = :client_id');
        $stmt->execute(['flag_id' => $flagId, 'client_id' => $clientId]);
    }
}
