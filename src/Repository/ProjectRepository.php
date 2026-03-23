<?php

declare(strict_types=1);

namespace Flagify\Repository;

use Flagify\Support\Clock;
use Flagify\Support\Uuid;
use PDO;

class ProjectRepository extends AbstractRepository
{
    public function __construct(PDO $pdo, private readonly Clock $clock)
    {
        parent::__construct($pdo);
    }

    public function create(array $data): array
    {
        $project = [
            'id' => Uuid::v7(),
            'name' => $data['name'],
            'slug' => $data['slug'],
            'description' => $data['description'] ?? null,
            'status' => 'active',
            'created_at' => $this->clock->now(),
            'updated_at' => $this->clock->now(),
            'deleted_at' => null,
        ];

        $stmt = $this->pdo->prepare(
            'INSERT INTO projects (id, name, slug, description, status, created_at, updated_at, deleted_at)
             VALUES (:id, :name, :slug, :description, :status, :created_at, :updated_at, :deleted_at)'
        );
        $stmt->execute($project);

        return $project;
    }

    public function paginate(int $limit, int $offset): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM projects WHERE status != :status ORDER BY created_at DESC LIMIT :limit OFFSET :offset'
        );
        $stmt->bindValue(':status', 'deleted');
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    public function count(): int
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM projects WHERE status != :status');
        $stmt->execute(['status' => 'deleted']);

        return (int) $stmt->fetchColumn();
    }

    public function find(string $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM projects WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    public function isActive(string $id): bool
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM projects WHERE id = :id AND status = :status');
        $stmt->execute(['id' => $id, 'status' => 'active']);

        return (int) $stmt->fetchColumn() === 1;
    }

    public function update(string $id, array $data): array
    {
        $fields = [];
        $params = ['id' => $id];

        foreach (['name', 'slug', 'description'] as $field) {
            if (array_key_exists($field, $data)) {
                $fields[] = sprintf('%s = :%s', $field, $field);
                $params[$field] = $data[$field];
            }
        }

        $fields[] = 'updated_at = :updated_at';
        $params['updated_at'] = $this->clock->now();

        $stmt = $this->pdo->prepare(sprintf('UPDATE projects SET %s WHERE id = :id', implode(', ', $fields)));
        $stmt->execute($params);

        return $this->find($id);
    }

    public function softDelete(string $id): void
    {
        $now = $this->clock->now();
        $stmt = $this->pdo->prepare(
            'UPDATE projects SET status = :status, updated_at = :updated_at, deleted_at = :deleted_at WHERE id = :id'
        );
        $stmt->execute([
            'id' => $id,
            'status' => 'deleted',
            'updated_at' => $now,
            'deleted_at' => $now,
        ]);

        $stmt = $this->pdo->prepare(
            'UPDATE clients SET status = :status, updated_at = :updated_at, deleted_at = :deleted_at
             WHERE project_id = :project_id AND status != :deleted_status'
        );
        $stmt->execute([
            'project_id' => $id,
            'status' => 'deleted',
            'updated_at' => $now,
            'deleted_at' => $now,
            'deleted_status' => 'deleted',
        ]);

        $stmt = $this->pdo->prepare(
            'UPDATE api_keys SET revoked_at = :revoked_at WHERE project_id = :project_id AND revoked_at IS NULL'
        );
        $stmt->execute([
            'project_id' => $id,
            'revoked_at' => $now,
        ]);
    }
}
