<?php

declare(strict_types=1);

namespace Flagify\Repository;

use Flagify\Support\Clock;
use Flagify\Support\Json;
use Flagify\Support\Uuid;
use PDO;

class AuditLogRepository extends AbstractRepository
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
            'environment_id' => $data['environment_id'] ?? null,
            'resource_type' => $data['resource_type'],
            'resource_id' => $data['resource_id'],
            'resource_key' => $data['resource_key'] ?? null,
            'action' => $data['action'],
            'actor_type' => $data['actor_type'],
            'actor_id' => $data['actor_id'] ?? null,
            'actor_name' => $data['actor_name'] ?? null,
            'request_id' => $data['request_id'] ?? null,
            'before_payload' => $data['before_payload'] ?? null,
            'after_payload' => $data['after_payload'] ?? null,
            'metadata' => $data['metadata'] ?? [],
            'created_at' => $this->clock->now(),
        ];

        $stmt = $this->pdo->prepare(
            'INSERT INTO audit_logs (id, project_id, environment_id, resource_type, resource_id, resource_key, action, actor_type, actor_id, actor_name, request_id, before_payload, after_payload, metadata, created_at)
             VALUES (:id, :project_id, :environment_id, :resource_type, :resource_id, :resource_key, :action, :actor_type, :actor_id, :actor_name, :request_id, :before_payload, :after_payload, :metadata, :created_at)'
        );
        $stmt->execute([
            ...$row,
            'before_payload' => $row['before_payload'] === null ? null : Json::encode($row['before_payload']),
            'after_payload' => $row['after_payload'] === null ? null : Json::encode($row['after_payload']),
            'metadata' => Json::encode($row['metadata']),
        ]);

        return $row;
    }

    public function paginateByProject(string $projectId, int $limit, int $offset, array $filters = []): array
    {
        $sql = 'SELECT * FROM audit_logs WHERE project_id = :project_id';
        $params = ['project_id' => $projectId];
        foreach ([
            'resource_type' => 'resource_type',
            'resource_id' => 'resource_id',
            'resource_key' => 'resource_key',
            'environment_id' => 'environment_id',
            'actor_id' => 'actor_id',
            'action' => 'action',
        ] as $filter => $column) {
            if (($filters[$filter] ?? null) !== null) {
                $sql .= sprintf(' AND %s = :%s', $column, $filter);
                $params[$filter] = $filters[$filter];
            }
        }
        if (($filters['created_from'] ?? null) !== null) {
            $sql .= ' AND created_at >= :created_from';
            $params['created_from'] = $filters['created_from'];
        }
        if (($filters['created_to'] ?? null) !== null) {
            $sql .= ' AND created_at <= :created_to';
            $params['created_to'] = $filters['created_to'];
        }
        $sql .= ' ORDER BY created_at DESC LIMIT :limit OFFSET :offset';

        $stmt = $this->pdo->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue(':' . $key, $value);
        }
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        return array_map(fn (array $row) => $this->decodeJsonFields($row, ['before_payload', 'after_payload', 'metadata']), $stmt->fetchAll());
    }

    public function countByProject(string $projectId, array $filters = []): int
    {
        $sql = 'SELECT COUNT(*) FROM audit_logs WHERE project_id = :project_id';
        $params = ['project_id' => $projectId];
        foreach ([
            'resource_type' => 'resource_type',
            'resource_id' => 'resource_id',
            'resource_key' => 'resource_key',
            'environment_id' => 'environment_id',
            'actor_id' => 'actor_id',
            'action' => 'action',
        ] as $filter => $column) {
            if (($filters[$filter] ?? null) !== null) {
                $sql .= sprintf(' AND %s = :%s', $column, $filter);
                $params[$filter] = $filters[$filter];
            }
        }
        if (($filters['created_from'] ?? null) !== null) {
            $sql .= ' AND created_at >= :created_from';
            $params['created_from'] = $filters['created_from'];
        }
        if (($filters['created_to'] ?? null) !== null) {
            $sql .= ' AND created_at <= :created_to';
            $params['created_to'] = $filters['created_to'];
        }

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return (int) $stmt->fetchColumn();
    }
}
