<?php

declare(strict_types=1);

namespace Flagify\Repository;

use Flagify\Support\Clock;
use Flagify\Support\Json;
use Flagify\Support\Uuid;
use PDO;

class ChangeRequestRepository extends AbstractRepository
{
    public function __construct(PDO $pdo, private readonly Clock $clock)
    {
        parent::__construct($pdo);
    }

    public function create(array $data): array
    {
        $row = [
            'id' => Uuid::v7(),
            'project_id' => $data['project_id'],
            'environment_id' => $data['environment_id'],
            'resource_type' => $data['resource_type'],
            'resource_id' => $data['resource_id'],
            'status' => $data['status'] ?? 'pending',
            'title' => $data['title'],
            'description' => $data['description'] ?? null,
            'proposed_by_principal_id' => $data['proposed_by_principal_id'] ?? null,
            'reviewed_by_principal_id' => $data['reviewed_by_principal_id'] ?? null,
            'applied_by_principal_id' => $data['applied_by_principal_id'] ?? null,
            'proposed_payload' => $data['proposed_payload'],
            'approved_payload' => $data['approved_payload'] ?? null,
            'base_snapshot_checksum' => $data['base_snapshot_checksum'] ?? null,
            'applied_at' => $data['applied_at'] ?? null,
            'created_at' => $this->clock->now(),
            'updated_at' => $this->clock->now(),
        ];

        $stmt = $this->pdo->prepare(
            'INSERT INTO change_requests (id, project_id, environment_id, resource_type, resource_id, status, title, description, proposed_by_principal_id, reviewed_by_principal_id, applied_by_principal_id, proposed_payload, approved_payload, base_snapshot_checksum, applied_at, created_at, updated_at)
             VALUES (:id, :project_id, :environment_id, :resource_type, :resource_id, :status, :title, :description, :proposed_by_principal_id, :reviewed_by_principal_id, :applied_by_principal_id, :proposed_payload, :approved_payload, :base_snapshot_checksum, :applied_at, :created_at, :updated_at)'
        );
        $stmt->execute([
            ...$row,
            'proposed_payload' => Json::encode($row['proposed_payload']),
            'approved_payload' => $row['approved_payload'] === null ? null : Json::encode($row['approved_payload']),
        ]);

        return $row;
    }

    public function find(string $projectId, string $changeRequestId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM change_requests WHERE project_id = :project_id AND id = :id LIMIT 1');
        $stmt->execute(['project_id' => $projectId, 'id' => $changeRequestId]);
        $row = $stmt->fetch();

        return $row ? $this->decodeJsonFields($row, ['proposed_payload', 'approved_payload']) : null;
    }

    public function paginateByProject(string $projectId, int $limit, int $offset): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM change_requests WHERE project_id = :project_id ORDER BY created_at DESC LIMIT :limit OFFSET :offset'
        );
        $stmt->bindValue(':project_id', $projectId);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        return array_map(fn (array $row) => $this->decodeJsonFields($row, ['proposed_payload', 'approved_payload']), $stmt->fetchAll());
    }

    public function countByProject(string $projectId): int
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM change_requests WHERE project_id = :project_id');
        $stmt->execute(['project_id' => $projectId]);

        return (int) $stmt->fetchColumn();
    }

    public function update(string $projectId, string $changeRequestId, array $data): array
    {
        $fields = [];
        $params = ['project_id' => $projectId, 'id' => $changeRequestId];
        foreach (['status', 'title', 'description', 'reviewed_by_principal_id', 'applied_by_principal_id', 'base_snapshot_checksum', 'applied_at'] as $field) {
            if (array_key_exists($field, $data)) {
                $fields[] = sprintf('%s = :%s', $field, $field);
                $params[$field] = $data[$field];
            }
        }
        foreach (['proposed_payload', 'approved_payload'] as $field) {
            if (array_key_exists($field, $data)) {
                $fields[] = sprintf('%s = :%s', $field, $field);
                $params[$field] = $data[$field] === null ? null : Json::encode($data[$field]);
            }
        }
        $fields[] = 'updated_at = :updated_at';
        $params['updated_at'] = $this->clock->now();

        $stmt = $this->pdo->prepare(sprintf(
            'UPDATE change_requests SET %s WHERE project_id = :project_id AND id = :id',
            implode(', ', $fields)
        ));
        $stmt->execute($params);

        return $this->find($projectId, $changeRequestId);
    }
}
