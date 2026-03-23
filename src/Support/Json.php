<?php

declare(strict_types=1);

namespace Flagify\Support;

use JsonException;

final class Json
{
    public static function decode(string $value): mixed
    {
        try {
            return json_decode($value, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new ApiError('validation_failed', 'Invalid JSON payload', 400);
        }
    }

    public static function encode(mixed $value): string
    {
        try {
            return json_encode($value, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new ApiError('internal_error', 'Failed to encode JSON response', 500);
        }
    }
}
