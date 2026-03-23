<?php

declare(strict_types=1);

namespace Flagify\Http\Middleware;

use Flagify\Auth\ApiKeyAuthenticator;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class AuthMiddleware implements MiddlewareInterface
{
    public const ATTRIBUTE = 'flagify.principal';

    public function __construct(private readonly ApiKeyAuthenticator $authenticator)
    {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $principal = $this->authenticator->authenticateRequest($request);

        return $handler->handle($request->withAttribute(self::ATTRIBUTE, $principal));
    }
}
