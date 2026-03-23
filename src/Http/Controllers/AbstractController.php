<?php

declare(strict_types=1);

namespace Flagify\Http\Controllers;

use DateTimeImmutable;
use DateTimeZone;
use Flagify\Auth\ApiPrincipal;
use Flagify\Http\Middleware\AuthMiddleware;
use Flagify\Support\Responder;
use Psr\Http\Message\ServerRequestInterface;

abstract class AbstractController
{
    public function __construct(protected readonly Responder $responder)
    {
    }

    protected function principal(ServerRequestInterface $request): ?ApiPrincipal
    {
        return $request->getAttribute(AuthMiddleware::ATTRIBUTE);
    }

    protected function normalizeResource(array $resource): array
    {
        foreach (['created_at', 'updated_at', 'deleted_at', 'last_used_at', 'expires_at', 'revoked_at'] as $field) {
            if (isset($resource[$field]) && is_string($resource[$field])) {
                $resource[$field] = (new DateTimeImmutable($resource[$field], new DateTimeZone('UTC')))->format(DATE_ATOM);
            }
        }

        return $resource;
    }

    protected function pagination(array $items, int $total, int $limit, int $offset): array
    {
        return [
            'items' => array_map(fn (array $item) => $this->normalizeResource($item), $items),
            'meta' => [
                'total' => $total,
                'limit' => $limit,
                'offset' => $offset,
            ],
        ];
    }
}
