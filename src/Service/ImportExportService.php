<?php

declare(strict_types=1);

namespace Flagify\Service;

use Flagify\Repository\EnvironmentRepository;
use Flagify\Repository\FlagEnvironmentRepository;
use Flagify\Repository\FlagRepository;
use Flagify\Repository\ProjectRepository;
use Flagify\Repository\SegmentRepository;

final class ImportExportService
{
    public function __construct(
        private readonly ProjectRepository $projects,
        private readonly EnvironmentRepository $environments,
        private readonly SegmentRepository $segments,
        private readonly FlagRepository $flags,
        private readonly FlagEnvironmentRepository $flagEnvironments
    ) {
    }

    public function exportProject(string $projectId): array
    {
        $project = $this->projects->find($projectId);
        $environments = $this->environments->allActiveByProject($projectId);
        usort($environments, fn (array $a, array $b): int => strcmp($a['key'], $b['key']));

        $segments = $this->segments->allActiveByProject($projectId);
        usort($segments, fn (array $a, array $b): int => strcmp($a['key'], $b['key']));

        $flags = $this->flags->activeByProject($projectId);
        usort($flags, fn (array $a, array $b): int => strcmp($a['key'], $b['key']));

        $configs = [];
        foreach ($flags as $flag) {
            foreach ($this->flagEnvironments->forFlag($flag['id']) as $config) {
                $environment = array_values(array_filter($environments, fn (array $entry): bool => $entry['id'] === $config['environment_id']))[0] ?? null;
                if ($environment === null) {
                    continue;
                }
                $configs[] = [
                    'flag_key' => $flag['key'],
                    'environment_key' => $environment['key'],
                    'default_value' => $config['default_value'],
                    'default_variant_key' => $config['default_variant_key'] ?? null,
                    'rules' => $config['rules'] ?? [],
                ];
            }
        }
        usort($configs, fn (array $a, array $b): int => [$a['environment_key'], $a['flag_key']] <=> [$b['environment_key'], $b['flag_key']]);

        return [
            'export_version' => '2026-03-23.v1',
            'project' => $project,
            'environments' => $environments,
            'flags' => $flags,
            'segments' => $segments,
            'flag_environment_configs' => $configs,
            'prerequisites' => array_values(array_filter(array_map(
                static fn (array $flag): array => [
                    'flag_key' => $flag['key'],
                    'prerequisites' => $flag['prerequisites'] ?? [],
                ],
                $flags
            ), static fn (array $entry): bool => $entry['prerequisites'] !== null && $entry['prerequisites'] !== [])),
            'variants' => array_values(array_filter(array_map(
                static fn (array $flag): array => [
                    'flag_key' => $flag['key'],
                    'variants' => $flag['variants'] ?? [],
                ],
                $flags
            ), static fn (array $entry): bool => $entry['variants'] !== null && $entry['variants'] !== [])),
        ];
    }
}
