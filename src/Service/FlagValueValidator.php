<?php

declare(strict_types=1);

namespace Flagify\Service;

use Flagify\Domain\FlagType;
use Flagify\Support\ApiError;

final class FlagValueValidator
{
    public function validateDefinition(string $type, mixed $defaultValue, mixed $options): array
    {
        $this->validateFlag($type, $defaultValue, $options);

        return [
            'default_value' => $defaultValue,
            'options' => $options,
        ];
    }

    public function validateFlag(string $type, mixed $defaultValue, mixed $options): void
    {
        if (!in_array($type, FlagType::all(), true)) {
            throw new ApiError('validation_failed', 'Unsupported flag type', 422);
        }

        $normalizedOptions = $this->validateOptions($type, $options);
        $this->validateValue($type, $defaultValue, $normalizedOptions, 'default_value');
    }

    public function validateVariants(string $type, mixed $options, mixed $variants, ?string $defaultVariantKey): ?array
    {
        if ($variants === null) {
            if ($defaultVariantKey !== null) {
                throw new ApiError('validation_failed', 'default_variant_key requires variants', 422);
            }

            return null;
        }

        if (!is_array($variants) || $variants === []) {
            throw new ApiError('validation_failed', 'variants must be a non-empty array', 422);
        }

        $allowed = $this->validateOptions($type, $options);
        $normalized = [];
        $keys = [];
        foreach ($variants as $index => $variant) {
            if (!is_array($variant)) {
                throw new ApiError('validation_failed', sprintf('variant %d must be an object', $index), 422);
            }
            $key = $variant['key'] ?? null;
            if (!is_string($key) || trim($key) === '') {
                throw new ApiError('validation_failed', sprintf('variant %d key is required', $index), 422);
            }
            if (in_array($key, $keys, true)) {
                throw new ApiError('validation_failed', 'variant keys must be unique', 422);
            }
            $value = $variant['value'] ?? null;
            $this->validateValue($type, $value, $allowed, sprintf('variants[%d].value', $index));
            $payload = $variant['payload'] ?? null;
            if ($payload !== null && !is_array($payload)) {
                throw new ApiError('validation_failed', sprintf('variants[%d].payload must be a JSON object', $index), 422);
            }

            $normalized[] = [
                'key' => trim($key),
                'name' => isset($variant['name']) && is_string($variant['name']) && trim($variant['name']) !== '' ? trim($variant['name']) : trim($key),
                'value' => $value,
                'payload' => $payload,
            ];
            $keys[] = trim($key);
        }

        if ($defaultVariantKey !== null && !in_array($defaultVariantKey, $keys, true)) {
            throw new ApiError('validation_failed', 'default_variant_key must match an existing variant key', 422);
        }

        return $normalized;
    }

    public function validatePrerequisites(mixed $value): ?array
    {
        if ($value === null) {
            return null;
        }
        if (!is_array($value)) {
            throw new ApiError('validation_failed', 'prerequisites must be an array', 422);
        }

        $normalized = [];
        foreach ($value as $index => $prerequisite) {
            if (!is_array($prerequisite)) {
                throw new ApiError('validation_failed', sprintf('prerequisites[%d] must be an object', $index), 422);
            }
            $flagKey = $prerequisite['flag_key'] ?? null;
            if (!is_string($flagKey) || trim($flagKey) === '') {
                throw new ApiError('validation_failed', sprintf('prerequisites[%d].flag_key is required', $index), 422);
            }

            $normalized[] = array_filter([
                'flag_key' => trim($flagKey),
                'expected_variant_key' => isset($prerequisite['expected_variant_key']) && is_string($prerequisite['expected_variant_key']) ? trim($prerequisite['expected_variant_key']) : null,
                'expected_value' => $prerequisite['expected_value'] ?? null,
            ], static fn (mixed $entry): bool => $entry !== null);
        }

        return $normalized;
    }

    public function validateValue(string $type, mixed $value, mixed $options, string $field = 'value'): void
    {
        if ($type === FlagType::BOOLEAN) {
            if (!is_bool($value)) {
                throw new ApiError('validation_failed', sprintf('%s must be a boolean', $field), 422);
            }

            return;
        }

        $allowed = $this->validateOptions($type, $options);

        if ($type === FlagType::SELECT) {
            if (!is_string($value) || !in_array($value, $allowed, true)) {
                throw new ApiError('validation_failed', sprintf('%s must be one of the allowed options', $field), 422);
            }

            return;
        }

        if (!is_array($value)) {
            throw new ApiError('validation_failed', sprintf('%s must be an array', $field), 422);
        }

        if (count($value) !== count(array_unique($value))) {
            throw new ApiError('validation_failed', sprintf('%s must not contain duplicate values', $field), 422);
        }

        foreach ($value as $entry) {
            if (!is_string($entry) || !in_array($entry, $allowed, true)) {
                throw new ApiError('validation_failed', sprintf('%s contains an invalid option', $field), 422);
            }
        }
    }

    public function assertOptionsCompatible(string $type, mixed $defaultValue, mixed $options, array $overrideValues): array
    {
        $validated = $this->validateDefinition($type, $defaultValue, $options);
        foreach ($overrideValues as $overrideValue) {
            $this->validateValue($type, $overrideValue, $validated['options'], 'override');
        }

        return $validated;
    }

    private function validateOptions(string $type, mixed $options): ?array
    {
        if ($type === FlagType::BOOLEAN) {
            if ($options !== null) {
                throw new ApiError('validation_failed', 'Boolean flags cannot define options', 422);
            }

            return null;
        }

        if (!is_array($options) || $options === []) {
            throw new ApiError('validation_failed', 'Select flags must define at least one option', 422);
        }

        if (count($options) > 100 || count($options) !== count(array_unique($options))) {
            throw new ApiError('validation_failed', 'Options must be unique and contain at most 100 entries', 422);
        }

        foreach ($options as $option) {
            if (!is_string($option) || $option === '' || strlen($option) > 100) {
                throw new ApiError('validation_failed', 'Each option must be a non-empty string up to 100 characters', 422);
            }
        }

        return array_values($options);
    }
}
