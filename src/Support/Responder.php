<?php

declare(strict_types=1);

namespace Flagify\Support;

use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;

final class Responder
{
    public function __construct(private readonly ResponseFactoryInterface $responseFactory)
    {
    }

    public function json(array $payload, int $status = 200): ResponseInterface
    {
        $response = $this->responseFactory->createResponse($status);
        $response->getBody()->write(Json::encode($payload));

        return $response->withHeader('Content-Type', 'application/json');
    }

    public function noContent(): ResponseInterface
    {
        return $this->responseFactory->createResponse(204);
    }
}
