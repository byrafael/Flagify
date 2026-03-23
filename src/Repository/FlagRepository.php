<?php

declare(strict_types=1);

namespace Flagify\Repository;

use Flagify\Support\Clock;
use Flagify\Support\Json;
use Flagify\Support\Uuid;
use PDO;

class FlagRepository extends AbstractRepository
{
    public function __construct(PDO $pdo, private readonly Clock $clock)
    {
        parent::__construct($pdo);
    }

    public function create(array $data): array
    {
        $flag = [
            'id' => Uuid::v7(),
            'project_id' => $data['project_id'],
            'key' => $data['key'],
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'flag_kind' => $data['flag_kind'] ?? 'release',
            'type' => $data['type'],
            'default_value' => $data['default_value'],
            'options' => $data['options'] ?? null,
            'variants' => $data['variants'] ?? null,
            'default_variant_key' => $data['default_variant_key'] ?? null,
            'status' => 'active',
            'created_at' => $this->clock->now(),
            'updated_at' => $this->clock->now(),
            'expires_at' => $data['expires_at'] ?? null,
            'last_evaluated_at' => null,
            'stale_status' => 'active',
            'prerequisites' => $data['prerequisites'] ?? null,
        ];

        $stmt = $this->pdo->prepare(
            'INSERT INTO flags (id, project_id, `key`, name, description, flag_kind, `type`, default_value, options, variants, default_variant_key, status, created_at, updated_at, expires_at, last_evaluated_at, stale_status, prerequisites)
             VALUES (:id, :project_id, :key, :name, :description, :flag_kind, :type, :default_value, :options, :variants, :default_variant_key, :status, :created_at, :updated_at, :expires_at, :last_evaluated_at, :stale_status, :prerequisites)'
        );
        $stmt->execute([
            ...$flag,
            'default_value' => Json::encode($flag['default_value']),
            'options' => $flag['options'] === null ? null : Json::encode($flag['options']),
            'variants' => $flag['variants'] === null ? null : Json::encode($flag['variants']),
            'prerequisites' => $flag['prerequisites'] === null ? null : Json::encode($flag['prerequisites']),
        ]);

        return $flag;
    }

    public function paginateByProject(string $projectId, int $limit, int $offset, bool $includeArchived): array
    {
        $sql = 'SELECT * FROM flags WHERE project_id = :project_id';
        if (!$includeArchived) {
            $sql .= ' AND status = :status';
        }
        $sql .= ' ORDER BY created_at DESC LIMIT :limit OFFSET :offset';

        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':project_id', $projectId);
        if (!$includeArchived) {
            $stmt->bindValue(':status', 'active');
        }
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        return array_map(fn (array $row) => $this->hydrate($row), $stmt->fetchAll());
    }

    public function countByProject(string $projectId, bool $includeArchived): int
    {
        $sql = 'SELECT COUNT(*) FROM flags WHERE project_id = :project_id';
        $params = ['project_id' => $projectId];
        if (!$includeArchived) {
            $sql .= ' AND status = :status';
            $params['status'] = 'active';
        }

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return (int) $stmt->fetchColumn();
    }

    public function find(string $projectId, string $flagId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM flags WHERE project_id = :project_id AND id = :id LIMIT 1');
        $stmt->execute(['project_id' => $projectId, 'id' => $flagId]);
        $row = $stmt->fetch();

        return $row ? $this->hydrate($row) : null;
    }

    public function findByKey(string $projectId, string $key): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM flags WHERE project_id = :project_id AND `key` = :key LIMIT 1');
        $stmt->execute(['project_id' => $projectId, 'key' => $key]);
        $row = $stmt->fetch();

        return $row ? $this->hydrate($row) : null;
    }

    public function activeByProject(string $projectId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM flags WHERE project_id = :project_id AND status = :status ORDER BY created_at ASC');
        $stmt->execute(['project_id' => $projectId, 'status' => 'active']);

        return array_map(fn (array $row) => $this->hydrate($row), $stmt->fetchAll());
    }

    public function update(string $projectId, string $flagId, array $data): array
    {
        $fields = [];
        $params = ['project_id' => $projectId, 'id' => $flagId];
        foreach (['key', 'name', 'description', 'flag_kind', 'options', 'default_value', 'variants', 'default_variant_key', 'status', 'expires_at', 'last_evaluated_at', 'stale_status', 'prerequisites'] as $field) {
            if (array_key_exists($field, $data)) {
                $fields[] = sprintf('%s = :%s', $field, $field);
                $params[$field] = in_array($field, ['options', 'default_value', 'variants', 'prerequisites'], true)
                    ? ($data[$field] === null ? null : Json::encode($data[$field]))
                    : $data[$field];
            }
        }

        $fields[] = 'updated_at = :updated_at';
        $params['updated_at'] = $this->clock->now();

        $stmt = $this->pdo->prepare(sprintf('UPDATE flags SET %s WHERE project_id = :project_id AND id = :id', implode(', ', $fields)));
        $stmt->execute($params);

        return $this->find($projectId, $flagId);
    }

    public function delete(string $projectId, string $flagId): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM flags WHERE project_id = :project_id AND id = :id');
        $stmt->execute(['project_id' => $projectId, 'id' => $flagId]);
    }

    public function touchLastEvaluatedAt(string $projectId, string $flagId): void
    {
        $flag = $this->find($projectId, $flagId);
        if ($flag === null) {
            return;
        }

        $staleStatus = $this->computeStaleStatus([
            ...$flag,
            'last_evaluated_at' => $this->clock->now(),
        ]);

        $stmt = $this->pdo->prepare(
            'UPDATE flags SET last_evaluated_at = :last_evaluated_at, stale_status = :stale_status WHERE project_id = :project_id AND id = :id'
        );
        $stmt->execute([
            'project_id' => $projectId,
            'id' => $flagId,
            'last_evaluated_at' => $this->clock->now(),
            'stale_status' => $staleStatus,
        ]);
    }

    private function hydrate(array $row): array
    {
        $row = $this->decodeJsonFields($row, ['default_value', 'options', 'variants', 'prerequisites']);
        $row['stale_status'] = $this->computeStaleStatus($row);

        return $row;
    }

    private function computeStaleStatus(array $flag): string
    {
        $now = strtotime($this->clock->now() . ' UTC');
        $expiresAt = isset($flag['expires_at']) && is_string($flag['expires_at'])
            ? strtotime($flag['expires_at'] . ' UTC')
            : false;
        if ($expiresAt !== false && $expiresAt !== null && $expiresAt < $now) {
            return 'stale';
        }

        $lastEvaluatedAt = isset($flag['last_evaluated_at']) && is_string($flag['last_evaluated_at'])
            ? strtotime($flag['last_evaluated_at'] . ' UTC')
            : false;
        if ($lastEvaluatedAt !== false && $lastEvaluatedAt !== null && $lastEvaluatedAt < ($now - 30 * 24 * 3600)) {
            return 'stale';
        }

        return 'active';
    }
}
