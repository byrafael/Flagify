<?php

declare(strict_types=1);

namespace Flagify\Repository;

use Flagify\Support\Clock;
use Flagify\Support\Json;
use Flagify\Support\Uuid;
use PDO;

class EvaluationEventRepository extends AbstractRepository
{
    public function __construct(PDO $pdo, private readonly Clock $clock)
    {
        parent::__construct($pdo);
    }

    public function create(array $data): array
    {
        $event = [
            'id' => Uuid::v7(),
            'project_id' => $data['project_id'],
            'environment_id' => $data['environment_id'],
            'flag_id' => $data['flag_id'],
            'client_id' => $data['client_id'],
            'variant_key' => $data['variant_key'] ?? null,
            'value' => $data['value'],
            'reason' => $data['reason'],
            'matched_rule' => $data['matched_rule'] ?? null,
            'context' => $data['context'],
            'created_at' => $this->clock->now(),
        ];

        $stmt = $this->pdo->prepare(
            'INSERT INTO evaluation_events (id, project_id, environment_id, flag_id, client_id, variant_key, value, reason, matched_rule, context, created_at)
             VALUES (:id, :project_id, :environment_id, :flag_id, :client_id, :variant_key, :value, :reason, :matched_rule, :context, :created_at)'
        );
        $stmt->execute([
            ...$event,
            'value' => Json::encode($event['value']),
            'context' => Json::encode($event['context']),
        ]);

        return $event;
    }

    public function paginateByProject(string $projectId, int $limit, int $offset, ?string $environmentId = null, ?string $flagId = null, ?string $clientId = null): array
    {
        $sql = 'SELECT * FROM evaluation_events WHERE project_id = :project_id';
        $params = ['project_id' => $projectId];
        if ($environmentId !== null) {
            $sql .= ' AND environment_id = :environment_id';
            $params['environment_id'] = $environmentId;
        }
        if ($flagId !== null) {
            $sql .= ' AND flag_id = :flag_id';
            $params['flag_id'] = $flagId;
        }
        if ($clientId !== null) {
            $sql .= ' AND client_id = :client_id';
            $params['client_id'] = $clientId;
        }
        $sql .= ' ORDER BY created_at DESC LIMIT :limit OFFSET :offset';

        $stmt = $this->pdo->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue(':' . $key, $value);
        }
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        return array_map(fn (array $row) => $this->decodeJsonFields($row, ['value', 'context']), $stmt->fetchAll());
    }

    public function countByProject(string $projectId, ?string $environmentId = null, ?string $flagId = null, ?string $clientId = null): int
    {
        $sql = 'SELECT COUNT(*) FROM evaluation_events WHERE project_id = :project_id';
        $params = ['project_id' => $projectId];
        if ($environmentId !== null) {
            $sql .= ' AND environment_id = :environment_id';
            $params['environment_id'] = $environmentId;
        }
        if ($flagId !== null) {
            $sql .= ' AND flag_id = :flag_id';
            $params['flag_id'] = $flagId;
        }
        if ($clientId !== null) {
            $sql .= ' AND client_id = :client_id';
            $params['client_id'] = $clientId;
        }

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return (int) $stmt->fetchColumn();
    }
}
