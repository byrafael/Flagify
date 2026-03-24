<?php

declare(strict_types=1);

namespace Flagify\Service;

use Flagify\Repository\FlagEnvironmentRepository;
use Flagify\Repository\FlagRepository;
use Flagify\Repository\OverrideRepository;
use Flagify\Repository\SegmentRepository;
use Flagify\Support\Clock;
use Flagify\Support\StickyBucketing;

final class FlagEvaluationService
{
    public function __construct(
        private readonly FlagRepository $flags,
        private readonly FlagEnvironmentRepository $flagEnvironments,
        private readonly SegmentRepository $segments,
        private readonly OverrideRepository $overrides,
        private readonly Clock $clock
    ) {
    }

    public function evaluateFlag(array $flag, array $environment, array $subject, array $segmentMap, array $overrideMap, array $flagMap, array $stack = []): array
    {
        if (in_array($flag['key'], $stack, true)) {
            return $this->fallbackResult($flag, $environment, null, 'cyclic_prerequisite');
        }

        $environmentConfig = $this->flagEnvironments->find($flag['id'], $environment['id']);
        if ($environmentConfig === null) {
            $environmentConfig = [
                'default_value' => $flag['default_value'],
                'default_variant_key' => $flag['default_variant_key'] ?? null,
                'rules' => [],
            ];
        }

        if (array_key_exists($flag['id'], $overrideMap)) {
            return $this->serve($flag, $overrideMap[$flag['id']], 'explicit_override', null, null);
        }

        foreach ($flag['prerequisites'] ?? [] as $prerequisite) {
            $prerequisiteFlag = $flagMap[$prerequisite['flag_key']] ?? null;
            if ($prerequisiteFlag === null) {
                return $this->fallbackResult($flag, $environment, $environmentConfig, 'missing_prerequisite');
            }

            $result = $this->evaluateFlag(
                $prerequisiteFlag,
                $environment,
                $subject,
                $segmentMap,
                $overrideMap,
                $flagMap,
                [...$stack, $flag['key']]
            );
            if (!$this->prerequisiteMatches($prerequisite, $result)) {
                return $this->fallbackResult($flag, $environment, $environmentConfig, 'prerequisite_not_met');
            }
        }

        foreach ($environmentConfig['rules'] ?? [] as $index => $rule) {
            if (!$this->isRuleActive($rule)) {
                continue;
            }
            if (!$this->segmentsMatch($rule['segment_keys'] ?? [], $segmentMap, $subject)) {
                continue;
            }
            if (!$this->conditionsMatch($rule['conditions'] ?? [], $subject)) {
                continue;
            }
            if (!$this->passesPercentage($rule['percentage'] ?? null, $rule['bucketing_key'] ?? 'key', $flag['key'], $environment['key'], $subject)) {
                continue;
            }

            return $this->serve(
                $flag,
                $rule['serve'] ?? [],
                'rule_match',
                $rule['name'] ?? ('rule-' . ($index + 1)),
                $environmentConfig
            );
        }

        return $this->fallbackResult($flag, $environment, $environmentConfig, 'default');
    }

    public function buildSnapshot(array $project, array $environment, array $flags, array $segments): array
    {
        $segmentPayload = [];
        foreach ($segments as $segment) {
            $segmentPayload[] = [
                'key' => $segment['key'],
                'name' => $segment['name'],
                'rules' => $segment['rules'],
            ];
        }

        $flagPayload = [];
        foreach ($flags as $flag) {
            $environmentConfig = $this->flagEnvironments->find($flag['id'], $environment['id']);
            $flagPayload[] = [
                'key' => $flag['key'],
                'type' => $flag['type'],
                'flag_kind' => $flag['flag_kind'],
                'default_value' => is_array($environmentConfig) && array_key_exists('default_value', $environmentConfig) && $environmentConfig['default_value'] !== null
                    ? $environmentConfig['default_value']
                    : $flag['default_value'],
                'default_variant_key' => is_array($environmentConfig)
                    ? ($environmentConfig['default_variant_key'] ?? $flag['default_variant_key'])
                    : ($flag['default_variant_key'] ?? null),
                'options' => $flag['options'],
                'variants' => $flag['variants'],
                'prerequisites' => $flag['prerequisites'],
                'rules' => is_array($environmentConfig) ? ($environmentConfig['rules'] ?? []) : [],
                'stale_status' => $flag['stale_status'],
            ];
        }

        return [
            'project' => [
                'id' => $project['id'],
                'slug' => $project['slug'],
            ],
            'environment' => [
                'id' => $environment['id'],
                'key' => $environment['key'],
                'name' => $environment['name'],
            ],
            'segments' => $segmentPayload,
            'flags' => $flagPayload,
            'meta' => [
                'generated_at' => $this->clock->nowIso(),
                'poll_ttl_seconds' => 30,
                'evaluation_precedence' => [
                    'client_override',
                    'prerequisites',
                    'rules',
                    'environment_default',
                    'flag_default',
                ],
                'trait_precedence' => [
                    'transient_traits',
                    'persisted_traits',
                    'client_metadata_fallback',
                ],
            ],
        ];
    }

    private function fallbackResult(array $flag, array $environment, ?array $environmentConfig, string $reason): array
    {
        $source = [
            'variant_key' => $environmentConfig['default_variant_key'] ?? $flag['default_variant_key'] ?? null,
            'value' => ($environmentConfig !== null && array_key_exists('default_value', $environmentConfig) && $environmentConfig['default_value'] !== null)
                ? $environmentConfig['default_value']
                : $flag['default_value'],
        ];

        return $this->serve($flag, $source, $reason, null, $environmentConfig);
    }

    private function serve(array $flag, array|bool|string $source, string $reason, ?string $matchedRule, ?array $environmentConfig): array
    {
        $variant = null;
        $value = null;
        if (is_array($source) && array_key_exists('variant_key', $source) && $source['variant_key'] !== null) {
            $variant = $this->findVariant($flag['variants'] ?? null, (string) $source['variant_key']);
        }

        if ($variant !== null) {
            $value = $variant['value'];
        } elseif (is_array($source) && array_key_exists('value', $source)) {
            $value = $source['value'];
        } else {
            $value = $flag['default_value'];
        }

        if ($variant === null && is_array($flag['variants'] ?? null)) {
            $variant = $this->findVariantByValue($flag['variants'], $value);
        }

        return [
            'id' => $flag['id'],
            'key' => $flag['key'],
            'type' => $flag['type'],
            'flag_kind' => $flag['flag_kind'],
            'value' => $value,
            'variant_key' => $variant['key'] ?? ($environmentConfig['default_variant_key'] ?? $flag['default_variant_key'] ?? null),
            'payload' => $variant['payload'] ?? null,
            'reason' => $reason,
            'matched_rule' => $matchedRule,
            'stale_status' => $flag['stale_status'],
        ];
    }

    private function prerequisiteMatches(array $prerequisite, array $result): bool
    {
        if (array_key_exists('expected_variant_key', $prerequisite) && $result['variant_key'] !== $prerequisite['expected_variant_key']) {
            return false;
        }
        if (array_key_exists('expected_value', $prerequisite) && $result['value'] !== $prerequisite['expected_value']) {
            return false;
        }

        return true;
    }

    private function isRuleActive(array $rule): bool
    {
        $schedule = $rule['schedule'] ?? null;
        if (!is_array($schedule)) {
            return true;
        }

        $now = time();
        $start = isset($schedule['start_at']) && is_string($schedule['start_at']) ? strtotime($schedule['start_at']) : false;
        if ($start !== false && $start !== null && $now < $start) {
            return false;
        }

        $end = isset($schedule['end_at']) && is_string($schedule['end_at']) ? strtotime($schedule['end_at']) : false;
        if ($end !== false && $end !== null && $now > $end) {
            return false;
        }

        return true;
    }

    private function segmentsMatch(array $segmentKeys, array $segmentMap, array $subject): bool
    {
        foreach ($segmentKeys as $segmentKey) {
            $segment = $segmentMap[$segmentKey] ?? null;
            if ($segment === null || !$this->conditionsMatch($segment['rules'] ?? [], $subject)) {
                return false;
            }
        }

        return true;
    }

    private function conditionsMatch(array $conditions, array $subject): bool
    {
        foreach ($conditions as $condition) {
            if (!is_array($condition)) {
                return false;
            }
            $attribute = is_string($condition['attribute'] ?? null) ? $condition['attribute'] : '';
            $operator = is_string($condition['operator'] ?? null) ? $condition['operator'] : 'equals';
            $actual = $this->attributeValue($subject, $attribute);
            $value = $condition['value'] ?? null;
            $values = $condition['values'] ?? null;

            if (!$this->matchesOperator($operator, $actual, $value, $values)) {
                return false;
            }
        }

        return true;
    }

    private function passesPercentage(mixed $percentage, string $bucketingKey, string $flagKey, string $environmentKey, array $subject): bool
    {
        if ($percentage === null) {
            return true;
        }
        if (!is_numeric($percentage)) {
            return false;
        }

        $bucketValue = $this->attributeValue($subject, $bucketingKey);
        if (!is_scalar($bucketValue) || $bucketValue === '') {
            return false;
        }

        return StickyBucketing::isIncluded(
            $environmentKey . ':' . $flagKey . ':' . (string) $bucketValue,
            (float) $percentage
        );
    }

    private function attributeValue(array $subject, string $attribute): mixed
    {
        if ($attribute === '') {
            return null;
        }

        $segments = explode('.', $attribute);
        $value = $subject;
        foreach ($segments as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return null;
            }
            $value = $value[$segment];
        }

        return $value;
    }

    private function matchesOperator(string $operator, mixed $actual, mixed $value, mixed $values): bool
    {
        return match ($operator) {
            'equals' => $actual === $value,
            'not_equals' => $actual !== $value,
            'in' => is_array($values) && in_array($actual, $values, true),
            'not_in' => is_array($values) && !in_array($actual, $values, true),
            'contains' => (is_string($actual) && is_string($value) && str_contains($actual, $value))
                || (is_array($actual) && in_array($value, $actual, true)),
            'not_contains' => (is_string($actual) && is_string($value) && !str_contains($actual, $value))
                || (is_array($actual) && !in_array($value, $actual, true)),
            'exists' => $actual !== null,
            'greater_than' => is_numeric($actual) && is_numeric($value) && (float) $actual > (float) $value,
            'greater_or_equal' => is_numeric($actual) && is_numeric($value) && (float) $actual >= (float) $value,
            'less_than' => is_numeric($actual) && is_numeric($value) && (float) $actual < (float) $value,
            'less_or_equal' => is_numeric($actual) && is_numeric($value) && (float) $actual <= (float) $value,
            'starts_with' => is_string($actual) && is_string($value) && str_starts_with($actual, $value),
            'ends_with' => is_string($actual) && is_string($value) && str_ends_with($actual, $value),
            default => false,
        };
    }

    private function findVariant(?array $variants, string $variantKey): ?array
    {
        if (!is_array($variants)) {
            return null;
        }

        foreach ($variants as $variant) {
            if (($variant['key'] ?? null) === $variantKey) {
                return $variant;
            }
        }

        return null;
    }

    private function findVariantByValue(array $variants, mixed $value): ?array
    {
        foreach ($variants as $variant) {
            if (($variant['value'] ?? null) === $value) {
                return $variant;
            }
        }

        return null;
    }
}
