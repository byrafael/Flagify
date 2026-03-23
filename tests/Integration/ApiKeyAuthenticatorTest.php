<?php

declare(strict_types=1);

namespace Flagify\Tests\Integration;

use Flagify\Auth\ApiKeyAuthenticator;
use Flagify\Domain\KeyKind;
use Flagify\Repository\ApiKeyRepository;
use Flagify\Repository\ClientRepository;
use Flagify\Repository\ProjectRepository;
use Flagify\Support\Clock;
use PHPUnit\Framework\TestCase;

final class ApiKeyAuthenticatorTest extends TestCase
{
    public function testBootstrapKeyAuthenticatesAsRoot(): void
    {
        $authenticator = new ApiKeyAuthenticator(
            $this->createMock(ApiKeyRepository::class),
            $this->createMock(ProjectRepository::class),
            $this->createMock(ClientRepository::class),
            new Clock(),
            'bootstrap-secret'
        );

        $principal = $authenticator->authenticateToken('bootstrap-secret');

        $this->assertSame(KeyKind::ROOT, $principal->kind);
    }
}
