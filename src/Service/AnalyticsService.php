<?php

declare(strict_types=1);

namespace Flagify\Service;

use Flagify\Repository\AnalyticsRepository;

final class AnalyticsService
{
    public function __construct(private readonly AnalyticsRepository $analytics)
    {
    }

    public function byFlag(string $projectId, array $filters, int $limit): array
    {
        return $this->analytics->byFlag($projectId, $filters, $limit);
    }

    public function byVariant(string $projectId, array $filters, int $limit): array
    {
        $rows = $this->analytics->byVariant($projectId, $filters, $limit);
        $totals = [];
        foreach ($rows as $row) {
            $totals[$row['flag_key']] = ($totals[$row['flag_key']] ?? 0) + (int) $row['total_evaluations'];
        }
        foreach ($rows as &$row) {
            $total = $totals[$row['flag_key']] ?? 0;
            $row['percentage'] = $total === 0 ? 0.0 : round(((int) $row['total_evaluations'] / $total) * 100, 2);
            if ($row['variant_key'] === '__null__') {
                $row['variant_key'] = null;
            }
        }

        return $rows;
    }

    public function byEnvironment(string $projectId, array $filters, int $limit): array
    {
        return $this->analytics->byEnvironment($projectId, $filters, $limit);
    }

    public function recentActivity(string $projectId, array $filters, int $limit): array
    {
        return $this->analytics->recentActivity($projectId, $filters, $limit);
    }
}
