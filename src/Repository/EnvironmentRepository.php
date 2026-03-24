<?php

declare(strict_types=1);

namespace Flagify\Repository;

use Flagify\Support\Clock;
use Flagify\Support\Uuid;
use PDO;

class EnvironmentRepository extends AbstractRepository
{
    public function __construct(PDO $pdo, private readonly Clock $clock)
    {
        parent::__construct($pdo);
    }

    public function createDefaultsForProject(string $projectId): void
    {
        $defaults = [
            ['key' => 'development', 'name' => 'Development', 'description' => 'Local and developer testing environment', 'is_default' => false, 'sort_order' => 10],
            ['key' => 'staging', 'name' => 'Staging', 'description' => 'Pre-production verification environment', 'is_default' => false, 'sort_order' => 20],
            ['key' => 'production', 'name' => 'Production', 'description' => 'Default live environment', 'is_default' => true, 'sort_order' => 30],
        ];

        foreach ($defaults as $environment) {
            $this->create([
                'project_id' => $projectId,
                ...$environment,
            ]);
        }
    }

    public function create(array $data): array
    {
        if (($data['is_default'] ?? false) === true) {
            $this->clearDefault($data['project_id']);
        }

        $environment = [
            'id' => Uuid::v7(),
            'project_id' => $data['project_id'],
            'key' => $data['key'],
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'is_default' => ($data['is_default'] ?? false) ? 1 : 0,
            'requires_change_requests' => ($data['requires_change_requests'] ?? false) ? 1 : 0,
            'sort_order' => $data['sort_order'] ?? 100,
            'status' => $data['status'] ?? 'active',
            'created_at' => $this->clock->now(),
            'updated_at' => $this->clock->now(),
            'deleted_at' => null,
        ];

        $stmt = $this->pdo->prepare(
            'INSERT INTO environments (id, project_id, `key`, name, description, is_default, requires_change_requests, sort_order, status, created_at, updated_at, deleted_at)
             VALUES (:id, :project_id, :key, :name, :description, :is_default, :requires_change_requests, :sort_order, :status, :created_at, :updated_at, :deleted_at)'
        );
        $stmt->execute($environment);

        return $environment;
    }

    public function paginateByProject(string $projectId, int $limit, int $offset): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM environments
             WHERE project_id = :project_id AND status != :status
             ORDER BY sort_order ASC, created_at ASC
             LIMIT :limit OFFSET :offset'
        );
        $stmt->bindValue(':project_id', $projectId);
        $stmt->bindValue(':status', 'deleted');
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    public function countByProject(string $projectId): int
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM environments WHERE project_id = :project_id AND status != :status');
        $stmt->execute(['project_id' => $projectId, 'status' => 'deleted']);

        return (int) $stmt->fetchColumn();
    }

    public function allActiveByProject(string $projectId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM environments
             WHERE project_id = :project_id AND status = :status
             ORDER BY sort_order ASC, created_at ASC'
        );
        $stmt->execute(['project_id' => $projectId, 'status' => 'active']);

        return $stmt->fetchAll();
    }

    public function find(string $projectId, string $environmentId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM environments WHERE project_id = :project_id AND id = :id LIMIT 1');
        $stmt->execute(['project_id' => $projectId, 'id' => $environmentId]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    public function findByKey(string $projectId, string $key): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM environments WHERE project_id = :project_id AND `key` = :key LIMIT 1');
        $stmt->execute(['project_id' => $projectId, 'key' => $key]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    public function findDefaultByProject(string $projectId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM environments
             WHERE project_id = :project_id AND status = :status
             ORDER BY is_default DESC, sort_order ASC, created_at ASC
             LIMIT 1'
        );
        $stmt->execute(['project_id' => $projectId, 'status' => 'active']);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    public function update(string $projectId, string $environmentId, array $data): array
    {
        if (($data['is_default'] ?? false) === true) {
            $this->clearDefault($projectId);
        }

        $fields = [];
        $params = ['project_id' => $projectId, 'id' => $environmentId];
        foreach (['key', 'name', 'description', 'sort_order', 'status'] as $field) {
            if (array_key_exists($field, $data)) {
                $fields[] = sprintf('%s = :%s', $field, $field);
                $params[$field] = $data[$field];
            }
        }
        if (array_key_exists('is_default', $data)) {
            $fields[] = 'is_default = :is_default';
            $params['is_default'] = $data['is_default'] ? 1 : 0;
        }
        if (array_key_exists('requires_change_requests', $data)) {
            $fields[] = 'requires_change_requests = :requires_change_requests';
            $params['requires_change_requests'] = $data['requires_change_requests'] ? 1 : 0;
        }
        if (($data['status'] ?? null) === 'deleted' && !array_key_exists('deleted_at', $data)) {
            $data['deleted_at'] = $this->clock->now();
        }
        if (array_key_exists('deleted_at', $data)) {
            $fields[] = 'deleted_at = :deleted_at';
            $params['deleted_at'] = $data['deleted_at'];
        }

        $fields[] = 'updated_at = :updated_at';
        $params['updated_at'] = $this->clock->now();

        $stmt = $this->pdo->prepare(sprintf(
            'UPDATE environments SET %s WHERE project_id = :project_id AND id = :id',
            implode(', ', $fields)
        ));
        $stmt->execute($params);

        return $this->find($projectId, $environmentId);
    }

    public function softDelete(string $projectId, string $environmentId): void
    {
        $this->update($projectId, $environmentId, [
            'status' => 'deleted',
            'deleted_at' => $this->clock->now(),
            'is_default' => false,
        ]);
    }

    private function clearDefault(string $projectId): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE environments SET is_default = 0, updated_at = :updated_at WHERE project_id = :project_id'
        );
        $stmt->execute([
            'project_id' => $projectId,
            'updated_at' => $this->clock->now(),
        ]);
    }
}
