<?php

declare(strict_types=1);

namespace Flagify\Tests\Support;

use Flagify\Support\AppFactory;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Slim\App;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Factory\StreamFactory;

abstract class ApiTestCase extends TestCase
{
    protected App $app;
    protected string $bootstrapKey = 'bootstrap-test-key';

    protected function setUp(): void
    {
        parent::setUp();

        $pdo = TestDatabase::create();
        $this->app = AppFactory::create([
            'app' => ['debug' => true],
            'db' => ['driver' => 'sqlite', 'database' => 'sqlite::memory:'],
            'bootstrap_key' => $this->bootstrapKey,
        ], $pdo);
    }

    protected function request(string $method, string $path, string $token, ?array $body = null): array
    {
        $requestFactory = new ServerRequestFactory();
        $streamFactory = new StreamFactory();

        $request = $requestFactory->createServerRequest($method, $path)
            ->withHeader('Accept', 'application/json')
            ->withHeader('Authorization', sprintf('Bearer %s', $token));

        if ($body !== null) {
            $json = json_encode($body, JSON_THROW_ON_ERROR);
            $request = $request
                ->withHeader('Content-Type', 'application/json')
                ->withBody($streamFactory->createStream($json));
        }

        $response = $this->app->handle($request);

        return [$response->getStatusCode(), $this->decode($response)];
    }

    protected function decode(ResponseInterface $response): array
    {
        $body = (string) $response->getBody();
        if ($body === '') {
            return [];
        }

        return json_decode($body, true, 512, JSON_THROW_ON_ERROR);
    }
}
