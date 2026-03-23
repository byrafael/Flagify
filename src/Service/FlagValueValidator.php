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
