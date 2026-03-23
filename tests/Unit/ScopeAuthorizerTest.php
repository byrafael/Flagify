<?php

declare(strict_types=1);

namespace Flagify\Tests\Unit;

use Flagify\Auth\ApiPrincipal;
use Flagify\Auth\ScopeAuthorizer;
use Flagify\Domain\KeyKind;
use Flagify\Support\ApiError;
use PHPUnit\Framework\TestCase;

final class ScopeAuthorizerTest extends TestCase
{
    public function testProjectScopedKeyCanAccessOwnProject(): void
    {
        $authorizer = new ScopeAuthorizer();
        $principal = new ApiPrincipal(KeyKind::PROJECT_ADMIN, [ScopeAuthorizer::FLAGS_WRITE], 'project-1');

        $result = $authorizer->requireScope($principal, ScopeAuthorizer::FLAGS_WRITE, 'project-1');

        $this->assertSame($principal, $result);
    }

    public function testProjectScopedKeyCannotAccessAnotherProject(): void
    {
        $this->expectException(ApiError::class);

        $authorizer = new ScopeAuthorizer();
        $principal = new ApiPrincipal(KeyKind::PROJECT_ADMIN, [ScopeAuthorizer::FLAGS_WRITE], 'project-1');
        $authorizer->requireScope($principal, ScopeAuthorizer::FLAGS_WRITE, 'project-2');
    }
}
