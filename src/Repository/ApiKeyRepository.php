<?php

declare(strict_types=1);

namespace Flagify\Repository;

use Flagify\Support\Clock;
use Flagify\Support\Json;
use Flagify\Support\Uuid;
use PDO;

class ApiKeyRepository extends AbstractRepository
{
    public function __construct(PDO $pdo, private readonly Clock $clock)
    {
        parent::__construct($pdo);
    }

    public function create(array $data): array
    {
        $row = [
            'id' => Uuid::v7(),
            'project_id' => $data['project_id'] ?? null,
            'client_id' => $data['client_id'] ?? null,
            'name' => $data['name'],
            'prefix' => $data['prefix'],
            'secret_hash' => $data['secret_hash'],
            'kind' => $data['kind'],
            'scopes' => $data['scopes'],
            'last_used_at' => null,
            'expires_at' => $data['expires_at'] ?? null,
            'revoked_at' => null,
            'created_at' => $this->clock->now(),
        ];

        $stmt = $this->pdo->prepare(
            'INSERT INTO api_keys (id, project_id, client_id, name, prefix, secret_hash, kind, scopes, last_used_at, expires_at, revoked_at, created_at)
             VALUES (:id, :project_id, :client_id, :name, :prefix, :secret_hash, :kind, :scopes, :last_used_at, :expires_at, :revoked_at, :created_at)'
        );
        $stmt->execute([
            ...$row,
            'scopes' => Json::encode($row['scopes']),
        ]);

        return $row;
    }

    public function paginate(int $limit, int $offset, ?string $projectId = null): array
    {
        $sql = 'SELECT * FROM api_keys';
        $params = [];
        if ($projectId !== null) {
            $sql .= ' WHERE project_id = :project_id';
            $params['project_id'] = $projectId;
        }
        $sql .= ' ORDER BY created_at DESC LIMIT :limit OFFSET :offset';

        $stmt = $this->pdo->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue(':' . $key, $value);
        }
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        return array_map(fn (array $row) => $this->decodeJsonFields($row, ['scopes']), $stmt->fetchAll());
    }

    public function count(?string $projectId = null): int
    {
        if ($projectId === null) {
            return (int) $this->pdo->query('SELECT COUNT(*) FROM api_keys')->fetchColumn();
        }

        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM api_keys WHERE project_id = :project_id');
        $stmt->execute(['project_id' => $projectId]);

        return (int) $stmt->fetchColumn();
    }

    public function find(string $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM api_keys WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();

        return $row ? $this->decodeJsonFields($row, ['scopes']) : null;
    }

    public function findByPrefix(string $prefix): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM api_keys WHERE prefix = :prefix LIMIT 1');
        $stmt->execute(['prefix' => $prefix]);
        $row = $stmt->fetch();

        return $row ? $this->decodeJsonFields($row, ['scopes']) : null;
    }

    public function revoke(string $id): void
    {
        $stmt = $this->pdo->prepare('UPDATE api_keys SET revoked_at = :revoked_at WHERE id = :id');
        $stmt->execute([
            'id' => $id,
            'revoked_at' => $this->clock->now(),
        ]);
    }

    public function touchLastUsedAt(string $id, string $timestamp): void
    {
        $stmt = $this->pdo->prepare('UPDATE api_keys SET last_used_at = :last_used_at WHERE id = :id');
        $stmt->execute([
            'id' => $id,
            'last_used_at' => $timestamp,
        ]);
    }
}
