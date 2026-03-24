<?php

declare(strict_types=1);

namespace Flagify\Service;

use Flagify\Repository\CodeReferenceRepository;
use Flagify\Repository\FlagRepository;
use Flagify\Support\ApiError;

final class CodeReferenceService
{
    public function __construct(
        private readonly CodeReferenceRepository $references,
        private readonly FlagRepository $flags
    ) {
    }

    public function ingest(string $projectId, array $references): array
    {
        $created = [];
        foreach ($references as $entry) {
            $flag = $this->flags->findByKey($projectId, $entry['flag_key']);
            if ($flag === null) {
                throw new ApiError('not_found', sprintf('Flag %s not found', $entry['flag_key']), 404);
            }

            $created[] = $this->references->create([
                'project_id' => $projectId,
                'flag_id' => $flag['id'],
                'source_type' => $entry['source_type'],
                'source_name' => $entry['source_name'] ?? null,
                'reference_path' => $entry['reference_path'],
                'reference_line' => $entry['reference_line'] ?? null,
                'reference_context' => $entry['reference_context'] ?? null,
                'observed_at' => $entry['observed_at'] ?? null,
            ]);
        }

        return $created;
    }

    public function staleReport(string $projectId, array $flags, ?string $evaluatedBefore = null, ?string $staleStatus = null, ?bool $hasCodeReferences = null): array
    {
        $rows = [];
        foreach ($flags as $flag) {
            $referenceCount = $this->references->countByFlag($projectId, $flag['id']);
            $latestObservedAt = $this->references->latestObservedAtByFlag($projectId, $flag['id']);

            $reason = null;
            if (($flag['status'] ?? 'active') === 'archived' && $referenceCount > 0) {
                $reason = 'archived_but_referenced';
            } elseif (($flag['expires_at'] ?? null) !== null && strtotime((string) $flag['expires_at'] . ' UTC') < time()) {
                $reason = 'expired_flag';
            } elseif (($flag['last_evaluated_at'] ?? null) === null && $referenceCount === 0) {
                $reason = 'never_evaluated_and_no_references';
            } elseif (($flag['last_evaluated_at'] ?? null) !== null && $referenceCount === 0) {
                $cutoff = $evaluatedBefore !== null ? strtotime($evaluatedBefore . ' UTC') : strtotime('-30 days');
                $lastEvaluated = strtotime((string) $flag['last_evaluated_at'] . ' UTC');
                if ($cutoff !== false && $lastEvaluated !== false && $lastEvaluated < $cutoff) {
                    $reason = 'not_evaluated_recently_and_no_references';
                }
            }

            if ($staleStatus !== null && $flag['stale_status'] !== $staleStatus) {
                continue;
            }
            if ($hasCodeReferences !== null && ($referenceCount > 0) !== $hasCodeReferences) {
                continue;
            }

            $rows[] = [
                'flag_id' => $flag['id'],
                'flag_key' => $flag['key'],
                'stale_status' => $flag['stale_status'],
                'last_evaluated_at' => $flag['last_evaluated_at'],
                'code_reference_count' => $referenceCount,
                'latest_code_reference_observed_at' => $latestObservedAt,
                'likely_stale_reason' => $reason,
            ];
        }

        return $rows;
    }
}
