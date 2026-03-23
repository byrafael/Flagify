<?php

declare(strict_types=1);

namespace Flagify\Domain;

final class FlagType
{
    public const BOOLEAN = 'boolean';
    public const SELECT = 'select';
    public const MULTI_SELECT = 'multi_select';

    public static function all(): array
    {
        return [self::BOOLEAN, self::SELECT, self::MULTI_SELECT];
    }
}
