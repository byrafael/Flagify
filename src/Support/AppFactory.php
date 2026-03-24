<?php

declare(strict_types=1);

namespace Flagify\Support;

use Flagify\Auth\ApiKeyAuthenticator;
use Flagify\Auth\ApiKeyGenerator;
use Flagify\Auth\ScopeAuthorizer;
use Flagify\Http\NativeApplication;
use Flagify\Repository\ApiKeyRepository;
use Flagify\Repository\ClientRepository;
use Flagify\Repository\EnvironmentRepository;
use Flagify\Repository\EvaluationEventRepository;
use Flagify\Repository\AnalyticsRepository;
use Flagify\Repository\AuditLogRepository;
use Flagify\Repository\ChangeRequestRepository;
use Flagify\Repository\CodeReferenceRepository;
use Flagify\Repository\FlagEnvironmentRepository;
use Flagify\Repository\FlagRepository;
use Flagify\Repository\IdentityRepository;
use Flagify\Repository\IdentityTraitRepository;
use Flagify\Repository\OverrideRepository;
use Flagify\Repository\ProjectRepository;
use Flagify\Repository\SegmentRepository;
use Flagify\Service\AnalyticsService;
use Flagify\Service\AuditLogService;
use Flagify\Service\ChangeRequestService;
use Flagify\Service\CodeReferenceService;
use Flagify\Service\EvaluationContextService;
use Flagify\Service\FlagEvaluationService;
use Flagify\Service\FlagValueValidator;
use Flagify\Service\IdentityService;
use Flagify\Service\ImportExportService;
use Flagify\Service\ResolvedConfigService;
use Flagify\Service\SnapshotService;
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
        $environments = new EnvironmentRepository($pdo, $clock);
        $segments = new SegmentRepository($pdo, $clock);
        $flagEnvironments = new FlagEnvironmentRepository($pdo, $clock);
        $overrides = new OverrideRepository($pdo, $clock);
        $events = new EvaluationEventRepository($pdo, $clock);
        $keys = new ApiKeyRepository($pdo, $clock);
        $identities = new IdentityRepository($pdo, $clock);
        $identityTraits = new IdentityTraitRepository($pdo, $clock);
        $auditLogs = new AuditLogRepository($pdo, $clock);
        $changeRequests = new ChangeRequestRepository($pdo, $clock);
        $codeReferences = new CodeReferenceRepository($pdo, $clock);
        $analyticsRepository = new AnalyticsRepository($pdo);
        $authorizer = new ScopeAuthorizer();
        $generator = new ApiKeyGenerator();
        $authenticator = new ApiKeyAuthenticator($keys, $projects, $clients, $clock, $config['bootstrap_key']);
        $validator = new FlagValueValidator();
        $identityService = new IdentityService($pdo, $identities, $identityTraits, $clients);
        $contexts = new EvaluationContextService($identityService);
        $snapshotService = new SnapshotService();
        $auditLogService = new AuditLogService($auditLogs);
        $evaluation = new FlagEvaluationService($flags, $flagEnvironments, $segments, $overrides, $clock);
        $resolvedConfig = new ResolvedConfigService($projects, $flags, $environments, $segments, $flagEnvironments, $overrides, $events, $contexts, $evaluation, $snapshotService, $clock);
        $analytics = new AnalyticsService($analyticsRepository);
        $changeRequestService = new ChangeRequestService($changeRequests, $environments, $flags, $flagEnvironments, $auditLogService);
        $importExport = new ImportExportService($projects, $environments, $segments, $flags, $flagEnvironments);
        $codeReferenceService = new CodeReferenceService($codeReferences, $flags);

        return new NativeApplication(
            $authenticator,
            $generator,
            $authorizer,
            $projects,
            $flags,
            $clients,
            $environments,
            $segments,
            $flagEnvironments,
            $overrides,
            $events,
            $keys,
            $validator,
            $resolvedConfig,
            $identities,
            $identityTraits,
            $auditLogs,
            $changeRequests,
            $codeReferences,
            $analytics,
            $identityService,
            $auditLogService,
            $changeRequestService,
            $importExport,
            $codeReferenceService
        );
    }
}
