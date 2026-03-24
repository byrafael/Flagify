<?php

declare(strict_types=1);

namespace Flagify\Service;

use Flagify\Auth\ApiPrincipal;
use Flagify\Repository\AuditLogRepository;

final class AuditLogService
{
    public function __construct(private readonly AuditLogRepository $logs)
    {
    }

    public function record(ApiPrincipal $principal, array $entry): array
    {
        return $this->logs->create([
            ...$entry,
            'actor_type' => $principal->kind,
            'actor_id' => $principal->keyId,
            'actor_name' => $principal->name,
            'request_id' => $this->requestId(),
            'metadata' => [
                'route' => $_SERVER['REQUEST_URI'] ?? '',
                'method' => strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET'),
                ...($entry['metadata'] ?? []),
            ],
        ]);
    }

    private function requestId(): ?string
    {
        $headers = function_exists('getallheaders') ? getallheaders() : [];
        $value = $headers['X-Request-Id'] ?? $headers['x-request-id'] ?? ($_SERVER['HTTP_X_REQUEST_ID'] ?? null);

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
