<?php

declare(strict_types=1);

namespace Flagify\Service;

use Flagify\Repository\EnvironmentRepository;
use Flagify\Repository\EvaluationEventRepository;
use Flagify\Repository\FlagEnvironmentRepository;
use Flagify\Repository\FlagRepository;
use Flagify\Repository\OverrideRepository;
use Flagify\Repository\ProjectRepository;
use Flagify\Repository\SegmentRepository;
use Flagify\Support\Clock;

final class ResolvedConfigService
{
    public function __construct(
        private readonly ProjectRepository $projects,
        private readonly FlagRepository $flags,
        private readonly EnvironmentRepository $environments,
        private readonly SegmentRepository $segments,
        private readonly FlagEnvironmentRepository $flagEnvironments,
        private readonly OverrideRepository $overrides,
        private readonly EvaluationEventRepository $events,
        private readonly EvaluationContextService $contexts,
        private readonly FlagEvaluationService $evaluator,
        private readonly SnapshotService $snapshots,
        private readonly Clock $clock
    ) {
    }

    public function resolveProjectClient(string $projectId, array $environment, array $client, bool $logEvents = true, array $transientTraits = []): array
    {
        $context = $this->contexts->fromClient($projectId, $client, $transientTraits);
        $payload = $this->resolveSubject($projectId, $environment, $context, $logEvents);
        $payload['client'] = [
            'id' => $client['id'],
            'key' => $client['key'],
        ];

        return $payload;
    }

    public function resolveProjectIdentity(string $projectId, array $environment, array $identity, bool $logEvents = true, array $transientTraits = []): array
    {
        $payload = $this->resolveSubject(
            $projectId,
            $environment,
            $this->contexts->fromIdentity($identity, $transientTraits),
            $logEvents
        );
        $payload['identity'] = [
            'id' => $identity['id'],
            'kind' => $identity['kind'],
            'identifier' => $identity['identifier'],
        ];

        return $payload;
    }

    private function resolveSubject(string $projectId, array $environment, array $context, bool $logEvents): array
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
        if (($context['client']['id'] ?? null) !== null) {
            foreach ($this->overrides->forClient($projectId, $context['client']['id']) as $override) {
                $overrideMap[$override['flag_id']] = $override['value'];
            }
        }

        $resolved = [];
        $evaluations = [];
        foreach ($flags as $flag) {
            $evaluation = $this->evaluator->evaluateFlag($flag, $environment, $context['subject'], $segmentMap, $overrideMap, $flagMap);
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
                    'client_id' => $context['client']['id'] ?? null,
                    'identity_id' => $context['identity']['id'],
                    'identity_kind' => $context['identity']['kind'],
                    'identity_identifier' => $context['identity']['identifier'],
                    'variant_key' => $evaluation['variant_key'],
                    'value' => $evaluation['value'],
                    'reason' => $evaluation['reason'],
                    'matched_rule' => $evaluation['matched_rule'],
                    'context' => [
                        'subject' => $context['subject'],
                        'environment' => $environment['key'],
                    ],
                    'traits' => $context['effective_traits'],
                    'transient_traits' => $context['transient_traits'],
                ]);
                $this->flags->touchLastEvaluatedAt($projectId, $flag['id']);
            }
        }

        $project = $this->projects->find($projectId);

        return [
            'project' => [
                'id' => $projectId,
                'slug' => $project['slug'] ?? null,
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
        $project = $this->projects->find($projectId);
        $snapshot = $this->evaluator->buildSnapshot(
            $project ?? ['id' => $projectId, 'slug' => null],
            $environment,
            $this->flags->activeByProject($projectId),
            $this->segments->allActiveByProject($projectId)
        );

        return $this->snapshots->finalize($snapshot);
    }

    public function defaultEnvironment(string $projectId): ?array
    {
        return $this->environments->findDefaultByProject($projectId);
    }
}
