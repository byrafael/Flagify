<?php

declare(strict_types=1);

namespace Flagify\Service;

use Flagify\Repository\EnvironmentRepository;
use Flagify\Repository\EvaluationEventRepository;
use Flagify\Repository\FlagEnvironmentRepository;
use Flagify\Repository\FlagRepository;
use Flagify\Repository\OverrideRepository;
use Flagify\Repository\SegmentRepository;
use Flagify\Support\Clock;

final class ResolvedConfigService
{
    public function __construct(
        private readonly FlagRepository $flags,
        private readonly EnvironmentRepository $environments,
        private readonly SegmentRepository $segments,
        private readonly FlagEnvironmentRepository $flagEnvironments,
        private readonly OverrideRepository $overrides,
        private readonly EvaluationEventRepository $events,
        private readonly FlagEvaluationService $evaluator,
        private readonly Clock $clock
    ) {
    }

    public function resolveProjectClient(string $projectId, array $environment, array $client, bool $logEvents = true): array
    {
        $flags = $this->flags->activeByProject($projectId);
        $flagMap = [];
        foreach ($flags as $flag) {
            $flagMap[$flag['key']] = $flag;
        }

        $segmentMap = [];
        foreach ($this->segments->allActiveByProject($projectId) as $segment) {
            $segmentMap[$segment['key']] = $segment;
        }

        $overrideMap = [];
        foreach ($this->overrides->forClient($projectId, $client['id']) as $override) {
            $overrideMap[$override['flag_id']] = $override['value'];
        }

        $resolved = [];
        $evaluations = [];
        foreach ($flags as $flag) {
            $evaluation = $this->evaluator->evaluateFlag($flag, $environment, $client, $segmentMap, $overrideMap, $flagMap);
            $resolved[$flag['key']] = [
                'value' => $evaluation['value'],
                'variant_key' => $evaluation['variant_key'],
                'payload' => $evaluation['payload'],
                'reason' => $evaluation['reason'],
                'matched_rule' => $evaluation['matched_rule'],
                'stale_status' => $evaluation['stale_status'],
            ];
            $evaluations[] = [$flag, $evaluation];
        }

        if ($logEvents) {
            foreach ($evaluations as [$flag, $evaluation]) {
                $this->events->create([
                    'project_id' => $projectId,
                    'environment_id' => $environment['id'],
                    'flag_id' => $flag['id'],
                    'client_id' => $client['id'],
                    'variant_key' => $evaluation['variant_key'],
                    'value' => $evaluation['value'],
                    'reason' => $evaluation['reason'],
                    'matched_rule' => $evaluation['matched_rule'],
                    'context' => [
                        'client' => [
                            'id' => $client['id'],
                            'key' => $client['key'],
                            'metadata' => $client['metadata'] ?? [],
                        ],
                        'environment' => $environment['key'],
                    ],
                ]);
                $this->flags->touchLastEvaluatedAt($projectId, $flag['id']);
            }
        }

        return [
            'project' => [
                'id' => $projectId,
            ],
            'client' => [
                'id' => $client['id'],
                'key' => $client['key'],
            ],
            'environment' => [
                'id' => $environment['id'],
                'key' => $environment['key'],
                'name' => $environment['name'],
            ],
            'flags' => $resolved,
            'meta' => [
                'resolved_at' => $this->clock->nowIso(),
            ],
        ];
    }

    public function buildSnapshot(string $projectId, array $environment): array
    {
        return $this->evaluator->buildSnapshot(
            $projectId,
            $environment,
            $this->flags->activeByProject($projectId),
            $this->segments->allActiveByProject($projectId)
        );
    }

    public function defaultEnvironment(string $projectId): ?array
    {
        return $this->environments->findDefaultByProject($projectId);
    }
}
