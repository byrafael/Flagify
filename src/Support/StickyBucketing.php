<?php

declare(strict_types=1);

namespace Flagify\Support;

final class StickyBucketing
{
    public static function isIncluded(string $identifier, float $percentage): bool
    {
        if ($percentage <= 0) {
            return false;
        }
        if ($percentage >= 100) {
            return true;
        }

        $bucket = ((int) sprintf('%u', crc32($identifier))) % 10_000;

        return $bucket < (int) round($percentage * 100);
    }
}
