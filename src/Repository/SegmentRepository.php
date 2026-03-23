<?php

declare(strict_types=1);

namespace Flagify\Repository;

use Flagify\Support\Clock;
use Flagify\Support\Json;
use Flagify\Support\Uuid;
use PDO;

class SegmentRepository extends AbstractRepository
{
    public function __construct(PDO $pdo, private readonly Clock $clock)
    {
        parent::__construct($pdo);
    }

    public function create(array $data): array
    {
        $segment = [
            'id' => Uuid::v7(),
            'project_id' => $data['project_id'],
            'key' => $data['key'],
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'rules' => $data['rules'],
            'status' => 'active',
            'created_at' => $this->clock->now(),
            'updated_at' => $this->clock->now(),
            'deleted_at' => null,
        ];

        $stmt = $this->pdo->prepare(
            'INSERT INTO segments (id, project_id, `key`, name, description, rules, status, created_at, updated_at, deleted_at)
             VALUES (:id, :project_id, :key, :name, :description, :rules, :status, :created_at, :updated_at, :deleted_at)'
        );
        $stmt->execute([
            ...$segment,
            'rules' => Json::encode($segment['rules']),
        ]);

        return $segment;
    }

    public function paginateByProject(string $projectId, int $limit, int $offset): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM segments
             WHERE project_id = :project_id AND status != :status
             ORDER BY created_at DESC
             LIMIT :limit OFFSET :offset'
        );
        $stmt->bindValue(':project_id', $projectId);
        $stmt->bindValue(':status', 'deleted');
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        return array_map(fn (array $row) => $this->decodeJsonFields($row, ['rules']), $stmt->fetchAll());
    }

    public function countByProject(string $projectId): int
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM segments WHERE project_id = :project_id AND status != :status');
        $stmt->execute(['project_id' => $projectId, 'status' => 'deleted']);

        return (int) $stmt->fetchColumn();
    }

    public function find(string $projectId, string $segmentId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM segments WHERE project_id = :project_id AND id = :id LIMIT 1');
        $stmt->execute(['project_id' => $projectId, 'id' => $segmentId]);
        $row = $stmt->fetch();

        return $row ? $this->decodeJsonFields($row, ['rules']) : null;
    }

    public function findByKey(string $projectId, string $key): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM segments WHERE project_id = :project_id AND `key` = :key LIMIT 1');
        $stmt->execute(['project_id' => $projectId, 'key' => $key]);
        $row = $stmt->fetch();

        return $row ? $this->decodeJsonFields($row, ['rules']) : null;
    }

    public function allActiveByProject(string $projectId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM segments WHERE project_id = :project_id AND status = :status ORDER BY created_at ASC'
        );
        $stmt->execute(['project_id' => $projectId, 'status' => 'active']);

        return array_map(fn (array $row) => $this->decodeJsonFields($row, ['rules']), $stmt->fetchAll());
    }

    public function update(string $projectId, string $segmentId, array $data): array
    {
        $fields = [];
        $params = ['project_id' => $projectId, 'id' => $segmentId];
        if (($data['status'] ?? null) === 'deleted' && !array_key_exists('deleted_at', $data)) {
            $data['deleted_at'] = $this->clock->now();
        }

        foreach (['key', 'name', 'description', 'status', 'deleted_at'] as $field) {
            if (array_key_exists($field, $data)) {
                $fields[] = sprintf('%s = :%s', $field, $field);
                $params[$field] = $data[$field];
            }
        }
        if (array_key_exists('rules', $data)) {
            $fields[] = 'rules = :rules';
            $params['rules'] = Json::encode($data['rules']);
        }

        $fields[] = 'updated_at = :updated_at';
        $params['updated_at'] = $this->clock->now();

        $stmt = $this->pdo->prepare(sprintf(
            'UPDATE segments SET %s WHERE project_id = :project_id AND id = :id',
            implode(', ', $fields)
        ));
        $stmt->execute($params);

        return $this->find($projectId, $segmentId);
    }

    public function softDelete(string $projectId, string $segmentId): void
    {
        $this->update($projectId, $segmentId, [
            'status' => 'deleted',
            'deleted_at' => $this->clock->now(),
        ]);
    }
}
