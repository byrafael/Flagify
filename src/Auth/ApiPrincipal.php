<?php

declare(strict_types=1);

namespace Flagify\Auth;

use Flagify\Domain\KeyKind;

final class ApiPrincipal
{
    public function __construct(
        public readonly string $kind,
        public readonly array $scopes,
        public readonly ?string $projectId = null,
        public readonly ?string $clientId = null,
        public readonly ?string $keyId = null,
        public readonly ?string $name = null
    ) {
    }

    public function isRoot(): bool
    {
        return $this->kind === KeyKind::ROOT;
    }
}
