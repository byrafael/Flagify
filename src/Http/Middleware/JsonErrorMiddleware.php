<?php

declare(strict_types=1);

namespace Flagify\Http\Middleware;

use Flagify\Support\ApiError;
use Flagify\Support\Responder;
use PDOException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Throwable;

final class JsonErrorMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly Responder $responder,
        private readonly bool $debug
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        try {
            return $handler->handle($request);
        } catch (ApiError $error) {
            return $this->responder->json([
                'error' => [
                    'code' => $error->codeName(),
                    'message' => $error->getMessage(),
                    'details' => $error->details(),
                ],
            ], $error->status());
        } catch (PDOException $error) {
            $message = strtolower($error->getMessage());
            $code = str_contains($message, 'duplicate entry') || str_contains($message, 'unique constraint failed')
                ? 'conflict'
                : 'internal_error';
            $status = $code === 'conflict' ? 409 : 500;

            return $this->responder->json([
                'error' => [
                    'code' => $code,
                    'message' => $code === 'conflict' ? 'Resource conflict' : 'Database error',
                    'details' => $this->debug ? ['exception' => $error->getMessage()] : [],
                ],
            ], $status);
        } catch (Throwable $error) {
            return $this->responder->json([
                'error' => [
                    'code' => 'internal_error',
                    'message' => 'Internal server error',
                    'details' => $this->debug ? ['exception' => $error->getMessage()] : [],
                ],
            ], 500);
        }
    }
}
