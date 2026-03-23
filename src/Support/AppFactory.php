<?php

declare(strict_types=1);

namespace Flagify\Support;

use Flagify\Auth\ApiKeyAuthenticator;
use Flagify\Auth\ApiKeyGenerator;
use Flagify\Auth\ScopeAuthorizer;
use Flagify\Http\NativeApplication;
use Flagify\Repository\ApiKeyRepository;
use Flagify\Repository\ClientRepository;
use Flagify\Repository\FlagRepository;
use Flagify\Repository\OverrideRepository;
use Flagify\Repository\ProjectRepository;
use Flagify\Service\FlagValueValidator;
use Flagify\Service\ResolvedConfigService;
use PDO;

final class AppFactory
{
    public static function create(array $overrides = [], ?PDO $pdo = null): NativeApplication
    {
        $root = dirname(__DIR__, 2);
        Env::load($root . '/.env');

        $config = [
            'db' => [
                'driver' => $_ENV['DB_DRIVER'] ?? 'mysql',
                'host' => $_ENV['DB_HOST'] ?? '127.0.0.1',
                'port' => $_ENV['DB_PORT'] ?? '3306',
                'database' => $_ENV['DB_DATABASE'] ?? 'flagify',
                'username' => $_ENV['DB_USERNAME'] ?? 'root',
                'password' => $_ENV['DB_PASSWORD'] ?? '',
            ],
            'bootstrap_key' => $_ENV['FLAGIFY_BOOTSTRAP_KEY'] ?? '',
        ];

        $config = array_replace_recursive($config, $overrides);
        $pdo ??= Database::connect($config['db']);
        $clock = new Clock();

        $projects = new ProjectRepository($pdo, $clock);
        $flags = new FlagRepository($pdo, $clock);
        $clients = new ClientRepository($pdo, $clock);
        $overrides = new OverrideRepository($pdo, $clock);
        $keys = new ApiKeyRepository($pdo, $clock);
        $authorizer = new ScopeAuthorizer();
        $generator = new ApiKeyGenerator();
        $authenticator = new ApiKeyAuthenticator($keys, $projects, $clients, $clock, $config['bootstrap_key']);
        $validator = new FlagValueValidator();
        $resolvedConfig = new ResolvedConfigService($flags, $overrides, $clock);

        return new NativeApplication(
            $authenticator,
            $generator,
            $authorizer,
            $projects,
            $flags,
            $clients,
            $overrides,
            $keys,
            $validator,
            $resolvedConfig
        );
    }
}
