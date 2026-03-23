<?php

declare(strict_types=1);

namespace Flagify\Support;

final class Arr
{
    public static function only(array $input, array $keys): array
    {
        $output = [];

        foreach ($keys as $key) {
            if (array_key_exists($key, $input)) {
                $output[$key] = $input[$key];
            }
        }

        return $output;
    }
}
