<?php

declare(strict_types=1);

namespace Flagify\Support;

final class Clock
{
    public function now(): string
    {
        $microtime = microtime(true);
        $seconds = (int) $microtime;
        $microseconds = (int) (($microtime - $seconds) * 1_000_000);

        return gmdate('Y-m-d H:i:s', $seconds) . sprintf('.%06d', $microseconds);
    }

    public function nowIso(): string
    {
        return gmdate('Y-m-d\TH:i:s\Z');
    }

    public function toIso(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $timestamp = strtotime($value . ' UTC');
        if ($timestamp === false) {
            return null;
        }

        return gmdate('Y-m-d\TH:i:s\Z', $timestamp);
    }
}
