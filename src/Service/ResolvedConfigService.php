<?php

declare(strict_types=1);

namespace Flagify\Service;

use Flagify\Repository\FlagRepository;
use Flagify\Repository\OverrideRepository;
use Flagify\Support\Clock;

final class ResolvedConfigService
{
    public function __construct(
        private readonly FlagRepository $flags,
        private readonly OverrideRepository $overrides,
        private readonly Clock $clock
    ) {
    }

    public function resolveProjectClient(string $projectId, array $client): array
    {
        $flags = $this->flags->activeByProject($projectId);
        $overrideMap = [];
        foreach ($this->overrides->forClient($projectId, $client['id']) as $override) {
            $overrideMap[$override['flag_id']] = $override['value'];
        }

        $resolved = [];
        foreach ($flags as $flag) {
            $resolved[$flag['key']] = $overrideMap[$flag['id']] ?? $flag['default_value'];
        }

        return [
            'project' => [
                'id' => $projectId,
            ],
            'client' => [
                'id' => $client['id'],
                'key' => $client['key'],
            ],
            'flags' => $resolved,
            'meta' => [
                'resolved_at' => $this->clock->nowIso(),
            ],
        ];
    }

    public function resolve(string $projectId, string $clientId): array
    {
        return $this->resolveProjectClient($projectId, [
            'id' => $clientId,
            'key' => '',
        ]);
    }
}
