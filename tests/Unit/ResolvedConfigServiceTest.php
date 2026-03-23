<?php

declare(strict_types=1);

namespace Flagify\Tests\Unit;

use Flagify\Repository\FlagRepository;
use Flagify\Repository\OverrideRepository;
use Flagify\Service\ResolvedConfigService;
use Flagify\Support\Clock;
use Flagify\Tests\Support\TestDatabase;
use PHPUnit\Framework\TestCase;

final class ResolvedConfigServiceTest extends TestCase
{
    public function testOverridesWinOverDefaults(): void
    {
        $pdo = TestDatabase::create();
        $clock = new Clock();

        $pdo->exec("INSERT INTO projects (id, name, slug, description, status, created_at, updated_at, deleted_at) VALUES ('project-1', 'Example', 'example', NULL, 'active', '2026-03-22 00:00:00.000000', '2026-03-22 00:00:00.000000', NULL)");
        $pdo->exec("INSERT INTO clients (id, project_id, key, name, description, status, metadata, created_at, updated_at, deleted_at) VALUES ('client-1', 'project-1', 'ios', 'iOS', NULL, 'active', '{}', '2026-03-22 00:00:00.000000', '2026-03-22 00:00:00.000000', NULL)");
        $pdo->exec("INSERT INTO flags (id, project_id, key, name, description, type, default_value, options, status, created_at, updated_at) VALUES ('flag-1', 'project-1', 'new_dashboard', 'New dashboard', NULL, 'boolean', 'false', NULL, 'active', '2026-03-22 00:00:00.000000', '2026-03-22 00:00:00.000000')");
        $pdo->exec("INSERT INTO flags (id, project_id, key, name, description, type, default_value, options, status, created_at, updated_at) VALUES ('flag-2', 'project-1', 'theme', 'Theme', NULL, 'select', '\"light\"', '[\"light\",\"dark\"]', 'active', '2026-03-22 00:00:00.000000', '2026-03-22 00:00:00.000000')");
        $pdo->exec("INSERT INTO flag_overrides (id, project_id, flag_id, client_id, value, created_at, updated_at) VALUES ('override-1', 'project-1', 'flag-1', 'client-1', 'true', '2026-03-22 00:00:00.000000', '2026-03-22 00:00:00.000000')");

        $service = new ResolvedConfigService(new FlagRepository($pdo, $clock), new OverrideRepository($pdo, $clock), $clock);
        $resolved = $service->resolveProjectClient('project-1', ['id' => 'client-1', 'key' => 'ios']);

        $this->assertSame(true, $resolved['flags']['new_dashboard']);
        $this->assertSame('light', $resolved['flags']['theme']);
    }
}
