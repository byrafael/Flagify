<?php

declare(strict_types=1);

namespace Flagify\Repository;

use PDO;

class AnalyticsRepository extends AbstractRepository
{
    public function byFlag(string $projectId, array $filters = [], int $limit = 50): array
    {
        [$whereSql, $params] = $this->filterSql($projectId, $filters);
        $sql = "SELECT e.flag_id,
                       f.`key` AS flag_key,
                       COUNT(*) AS total_evaluations,
                       MAX(e.created_at) AS last_evaluated_at,
                       COUNT(DISTINCT e.environment_id) AS distinct_environments_seen
                FROM evaluation_events e
                JOIN flags f ON f.id = e.flag_id
                WHERE {$whereSql}
                GROUP BY e.flag_id, f.`key`
                ORDER BY total_evaluations DESC, f.`key` ASC
                LIMIT :limit";
        $stmt = $this->pdo->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue(':' . $key, $value);
        }
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    public function byVariant(string $projectId, array $filters = [], int $limit = 50): array
    {
        [$whereSql, $params] = $this->filterSql($projectId, $filters);
        $sql = "SELECT e.flag_id,
                       f.`key` AS flag_key,
                       COALESCE(e.variant_key, '__null__') AS variant_key,
                       COUNT(*) AS total_evaluations
                FROM evaluation_events e
                JOIN flags f ON f.id = e.flag_id
                WHERE {$whereSql}
                GROUP BY e.flag_id, f.`key`, COALESCE(e.variant_key, '__null__')
                ORDER BY total_evaluations DESC, f.`key` ASC
                LIMIT :limit";
        $stmt = $this->pdo->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue(':' . $key, $value);
        }
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    public function byEnvironment(string $projectId, array $filters = [], int $limit = 50): array
    {
        [$whereSql, $params] = $this->filterSql($projectId, $filters);
        $sql = "SELECT e.environment_id,
                       env.`key` AS environment_key,
                       COUNT(*) AS total_evaluations,
                       COUNT(DISTINCT e.flag_id) AS distinct_flags_evaluated,
                       COUNT(DISTINCT COALESCE(e.identity_id, e.client_id)) AS distinct_subjects_evaluated
                FROM evaluation_events e
                JOIN environments env ON env.id = e.environment_id
                WHERE {$whereSql}
                GROUP BY e.environment_id, env.`key`
                ORDER BY total_evaluations DESC, env.`key` ASC
                LIMIT :limit";
        $stmt = $this->pdo->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue(':' . $key, $value);
        }
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    public function recentActivity(string $projectId, array $filters = [], int $limit = 50): array
    {
        [$whereSql, $params] = $this->filterSql($projectId, $filters);
        $sql = "SELECT e.*,
                       f.`key` AS flag_key,
                       env.`key` AS environment_key,
                       env.name AS environment_name
                FROM evaluation_events e
                JOIN flags f ON f.id = e.flag_id
                JOIN environments env ON env.id = e.environment_id
                WHERE {$whereSql}
                ORDER BY e.created_at DESC
                LIMIT :limit";
        $stmt = $this->pdo->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue(':' . $key, $value);
        }
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        return array_map(fn (array $row) => $this->decodeJsonFields($row, ['value', 'context', 'traits', 'transient_traits']), $stmt->fetchAll());
    }

    private function filterSql(string $projectId, array $filters): array
    {
        $whereSql = 'e.project_id = :project_id';
        $params = ['project_id' => $projectId];

        foreach (['environment_id' => 'environment_id', 'flag_id' => 'flag_id'] as $filter => $column) {
            if (($filters[$filter] ?? null) !== null) {
                $whereSql .= sprintf(' AND e.%s = :%s', $column, $filter);
                $params[$filter] = $filters[$filter];
            }
        }
        if (($filters['created_from'] ?? null) !== null) {
            $whereSql .= ' AND e.created_at >= :created_from';
            $params['created_from'] = $filters['created_from'];
        }
        if (($filters['created_to'] ?? null) !== null) {
            $whereSql .= ' AND e.created_at <= :created_to';
            $params['created_to'] = $filters['created_to'];
        }

        return [$whereSql, $params];
    }
}
