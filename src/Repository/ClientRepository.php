<?php

declare(strict_types=1);

namespace Flagify\Repository;

use Flagify\Support\Clock;
use Flagify\Support\Json;
use Flagify\Support\Uuid;
use PDO;

class ClientRepository extends AbstractRepository
{
    public function __construct(PDO $pdo, private readonly Clock $clock)
    {
        parent::__construct($pdo);
    }

    public function create(array $data): array
    {
        $client = [
            'id' => Uuid::v7(),
            'project_id' => $data['project_id'],
            'key' => $data['key'],
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'status' => $data['status'] ?? 'active',
            'metadata' => $data['metadata'] ?? [],
            'created_at' => $this->clock->now(),
            'updated_at' => $this->clock->now(),
            'deleted_at' => null,
        ];

        $stmt = $this->pdo->prepare(
            'INSERT INTO clients (id, project_id, `key`, name, description, status, metadata, created_at, updated_at, deleted_at)
             VALUES (:id, :project_id, :key, :name, :description, :status, :metadata, :created_at, :updated_at, :deleted_at)'
        );
        $stmt->execute([
            ...$client,
            'metadata' => Json::encode($client['metadata']),
        ]);

        return $client;
    }

    public function paginateByProject(string $projectId, int $limit, int $offset): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM clients WHERE project_id = :project_id AND status != :status ORDER BY created_at DESC LIMIT :limit OFFSET :offset'
        );
        $stmt->bindValue(':project_id', $projectId);
        $stmt->bindValue(':status', 'deleted');
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        return array_map(fn (array $row) => $this->decodeJsonFields($row, ['metadata']), $stmt->fetchAll());
    }

    public function countByProject(string $projectId): int
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM clients WHERE project_id = :project_id AND status != :status');
        $stmt->execute(['project_id' => $projectId, 'status' => 'deleted']);

        return (int) $stmt->fetchColumn();
    }

    public function find(string $projectId, string $clientId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM clients WHERE project_id = :project_id AND id = :id LIMIT 1');
        $stmt->execute(['project_id' => $projectId, 'id' => $clientId]);
        $row = $stmt->fetch();

        return $row ? $this->decodeJsonFields($row, ['metadata']) : null;
    }

    public function findByKey(string $projectId, string $key): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM clients WHERE project_id = :project_id AND `key` = :key LIMIT 1');
        $stmt->execute(['project_id' => $projectId, 'key' => $key]);
        $row = $stmt->fetch();

        return $row ? $this->decodeJsonFields($row, ['metadata']) : null;
    }

    public function findById(string $clientId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM clients WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $clientId]);
        $row = $stmt->fetch();

        return $row ? $this->decodeJsonFields($row, ['metadata']) : null;
    }

    public function isResolvable(string $clientId): bool
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM clients WHERE id = :id AND status = :status AND deleted_at IS NULL');
        $stmt->execute(['id' => $clientId, 'status' => 'active']);

        return (int) $stmt->fetchColumn() === 1;
    }

    public function update(string $projectId, string $clientId, array $data): array
    {
        $fields = [];
        $params = ['project_id' => $projectId, 'id' => $clientId];
        if (($data['status'] ?? null) === 'deleted' && !array_key_exists('deleted_at', $data)) {
            $data['deleted_at'] = $this->clock->now();
        }
        foreach (['key', 'name', 'description', 'status', 'metadata', 'deleted_at'] as $field) {
            if (array_key_exists($field, $data)) {
                $fields[] = sprintf('%s = :%s', $field, $field);
                $params[$field] = $field === 'metadata' ? Json::encode($data[$field]) : $data[$field];
            }
        }

        $fields[] = 'updated_at = :updated_at';
        $params['updated_at'] = $this->clock->now();

        $stmt = $this->pdo->prepare(sprintf('UPDATE clients SET %s WHERE project_id = :project_id AND id = :id', implode(', ', $fields)));
        $stmt->execute($params);

        return $this->find($projectId, $clientId);
    }

    public function softDelete(string $projectId, string $clientId): void
    {
        $now = $this->clock->now();
        $this->update($projectId, $clientId, [
            'status' => 'deleted',
            'deleted_at' => $now,
        ]);
    }
}
