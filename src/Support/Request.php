<?php

declare(strict_types=1);

namespace Flagify\Support;

use Psr\Http\Message\ServerRequestInterface;

final class Request
{
    public static function json(ServerRequestInterface $request): array
    {
        $parsed = $request->getParsedBody();

        if ($parsed === null) {
            return [];
        }

        if (!is_array($parsed)) {
            throw new ApiError('validation_failed', 'Request body must be a JSON object', 422);
        }

        return $parsed;
    }

    public static function intQuery(ServerRequestInterface $request, string $key, int $default, int $max = 200): int
    {
        $params = $request->getQueryParams();
        $value = isset($params[$key]) ? (int) $params[$key] : $default;
        if ($value < 1 || $value > $max) {
            throw new ApiError('validation_failed', sprintf('%s must be between 1 and %d', $key, $max), 422);
        }

        return $value;
    }

    public static function nonNegativeIntQuery(ServerRequestInterface $request, string $key, int $default, int $max = 1000000): int
    {
        $params = $request->getQueryParams();
        $value = isset($params[$key]) ? (int) $params[$key] : $default;
        if ($value < 0 || $value > $max) {
            throw new ApiError('validation_failed', sprintf('%s must be between 0 and %d', $key, $max), 422);
        }

        return $value;
    }

    public static function boolQuery(ServerRequestInterface $request, string $key, bool $default = false): bool
    {
        $params = $request->getQueryParams();
        if (!array_key_exists($key, $params)) {
            return $default;
        }

        return filter_var($params[$key], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? $default;
    }
}
