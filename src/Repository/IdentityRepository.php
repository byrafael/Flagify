<?php

declare(strict_types=1);

namespace Flagify\Repository;

use Flagify\Support\Clock;
use Flagify\Support\Uuid;
use PDO;

class IdentityRepository extends AbstractRepository
{
    public function __construct(PDO $pdo, private readonly Clock $clock)
    {
        parent::__construct($pdo);
    }

    public function create(array $data): array
    {
        $identity = [
            'id' => Uuid::v7(),
            'project_id' => $data['project_id'],
            'kind' => $data['kind'],
            'identifier' => $data['identifier'],
            'display_name' => $data['display_name'] ?? null,
            'description' => $data['description'] ?? null,
            'status' => $data['status'] ?? 'active',
            'client_id' => $data['client_id'] ?? null,
            'created_at' => $this->clock->now(),
            'updated_at' => $this->clock->now(),
            'deleted_at' => $data['deleted_at'] ?? null,
        ];

        $stmt = $this->pdo->prepare(
            'INSERT INTO identities (id, project_id, kind, identifier, display_name, description, status, client_id, created_at, updated_at, deleted_at)
             VALUES (:id, :project_id, :kind, :identifier, :display_name, :description, :status, :client_id, :created_at, :updated_at, :deleted_at)'
        );
        $stmt->execute($identity);

        return $identity;
    }

    public function paginateByProject(string $projectId, int $limit, int $offset, ?string $kind = null, ?string $identifier = null, ?string $status = null): array
    {
        $sql = 'SELECT * FROM identities WHERE project_id = :project_id';
        $params = ['project_id' => $projectId];
        if ($kind !== null) {
            $sql .= ' AND kind = :kind';
            $params['kind'] = $kind;
        }
        if ($identifier !== null) {
            $sql .= ' AND identifier = :identifier';
            $params['identifier'] = $identifier;
        }
        if ($status !== null) {
            $sql .= ' AND status = :status';
            $params['status'] = $status;
        } else {
            $sql .= ' AND status != :deleted_status';
            $params['deleted_status'] = 'deleted';
        }

        $sql .= ' ORDER BY created_at DESC LIMIT :limit OFFSET :offset';
        $stmt = $this->pdo->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue(':' . $key, $value);
        }
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    public function countByProject(string $projectId, ?string $kind = null, ?string $identifier = null, ?string $status = null): int
    {
        $sql = 'SELECT COUNT(*) FROM identities WHERE project_id = :project_id';
        $params = ['project_id' => $projectId];
        if ($kind !== null) {
            $sql .= ' AND kind = :kind';
            $params['kind'] = $kind;
        }
        if ($identifier !== null) {
            $sql .= ' AND identifier = :identifier';
            $params['identifier'] = $identifier;
        }
        if ($status !== null) {
            $sql .= ' AND status = :status';
            $params['status'] = $status;
        } else {
            $sql .= ' AND status != :deleted_status';
            $params['deleted_status'] = 'deleted';
        }

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return (int) $stmt->fetchColumn();
    }

    public function find(string $projectId, string $identityId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM identities WHERE project_id = :project_id AND id = :id LIMIT 1');
        $stmt->execute(['project_id' => $projectId, 'id' => $identityId]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    public function findByKindAndIdentifier(string $projectId, string $kind, string $identifier): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM identities WHERE project_id = :project_id AND kind = :kind AND identifier = :identifier LIMIT 1'
        );
        $stmt->execute(['project_id' => $projectId, 'kind' => $kind, 'identifier' => $identifier]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    public function findByClientId(string $projectId, string $clientId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM identities WHERE project_id = :project_id AND client_id = :client_id LIMIT 1'
        );
        $stmt->execute(['project_id' => $projectId, 'client_id' => $clientId]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    public function update(string $projectId, string $identityId, array $data): array
    {
        $fields = [];
        $params = ['project_id' => $projectId, 'id' => $identityId];
        foreach (['kind', 'identifier', 'display_name', 'description', 'status', 'client_id', 'deleted_at'] as $field) {
            if (array_key_exists($field, $data)) {
                $fields[] = sprintf('%s = :%s', $field, $field);
                $params[$field] = $data[$field];
            }
        }

        $fields[] = 'updated_at = :updated_at';
        $params['updated_at'] = $this->clock->now();

        $stmt = $this->pdo->prepare(sprintf('UPDATE identities SET %s WHERE project_id = :project_id AND id = :id', implode(', ', $fields)));
        $stmt->execute($params);

        return $this->find($projectId, $identityId);
    }

    public function softDelete(string $projectId, string $identityId): array
    {
        return $this->update($projectId, $identityId, [
            'status' => 'deleted',
            'deleted_at' => $this->clock->now(),
        ]);
    }
}
