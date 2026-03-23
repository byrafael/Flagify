<?php

declare(strict_types=1);

namespace Flagify\Support;

final class Uuid
{
    public static function v7(): string
    {
        $milliseconds = (int) floor(microtime(true) * 1000);
        $bytes = random_bytes(16);

        $bytes[0] = chr(($milliseconds >> 40) & 0xff);
        $bytes[1] = chr(($milliseconds >> 32) & 0xff);
        $bytes[2] = chr(($milliseconds >> 24) & 0xff);
        $bytes[3] = chr(($milliseconds >> 16) & 0xff);
        $bytes[4] = chr(($milliseconds >> 8) & 0xff);
        $bytes[5] = chr($milliseconds & 0xff);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x70);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);

        $hex = bin2hex($bytes);

        return sprintf(
            '%s-%s-%s-%s-%s',
            substr($hex, 0, 8),
            substr($hex, 8, 4),
            substr($hex, 12, 4),
            substr($hex, 16, 4),
            substr($hex, 20, 12)
        );
    }
}
