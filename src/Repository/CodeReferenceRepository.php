<?php

declare(strict_types=1);

namespace Flagify\Repository;

use Flagify\Support\Clock;
use Flagify\Support\Uuid;
use PDO;

class CodeReferenceRepository extends AbstractRepository
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
            'flag_id' => $data['flag_id'],
            'source_type' => $data['source_type'],
            'source_name' => $data['source_name'] ?? null,
            'reference_path' => $data['reference_path'],
            'reference_line' => $data['reference_line'] ?? null,
            'reference_context' => $data['reference_context'] ?? null,
            'observed_at' => $data['observed_at'] ?? $this->clock->now(),
            'created_at' => $this->clock->now(),
            'updated_at' => $this->clock->now(),
        ];

        $stmt = $this->pdo->prepare(
            'INSERT INTO code_references (id, project_id, flag_id, source_type, source_name, reference_path, reference_line, reference_context, observed_at, created_at, updated_at)
             VALUES (:id, :project_id, :flag_id, :source_type, :source_name, :reference_path, :reference_line, :reference_context, :observed_at, :created_at, :updated_at)'
        );
        $stmt->execute($row);

        return $row;
    }

    public function paginateByProject(string $projectId, int $limit, int $offset): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM code_references WHERE project_id = :project_id ORDER BY observed_at DESC, created_at DESC LIMIT :limit OFFSET :offset'
        );
        $stmt->bindValue(':project_id', $projectId);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    public function countByProject(string $projectId): int
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM code_references WHERE project_id = :project_id');
        $stmt->execute(['project_id' => $projectId]);

        return (int) $stmt->fetchColumn();
    }

    public function find(string $projectId, string $referenceId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM code_references WHERE project_id = :project_id AND id = :id LIMIT 1');
        $stmt->execute(['project_id' => $projectId, 'id' => $referenceId]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    public function delete(string $projectId, string $referenceId): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM code_references WHERE project_id = :project_id AND id = :id');
        $stmt->execute(['project_id' => $projectId, 'id' => $referenceId]);
    }

    public function countByFlag(string $projectId, string $flagId): int
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM code_references WHERE project_id = :project_id AND flag_id = :flag_id');
        $stmt->execute(['project_id' => $projectId, 'flag_id' => $flagId]);

        return (int) $stmt->fetchColumn();
    }

    public function latestObservedAtByFlag(string $projectId, string $flagId): ?string
    {
        $stmt = $this->pdo->prepare('SELECT MAX(observed_at) FROM code_references WHERE project_id = :project_id AND flag_id = :flag_id');
        $stmt->execute(['project_id' => $projectId, 'flag_id' => $flagId]);
        $value = $stmt->fetchColumn();

        return is_string($value) ? $value : null;
    }
}
