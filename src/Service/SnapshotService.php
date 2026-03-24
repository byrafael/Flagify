<?php

declare(strict_types=1);

namespace Flagify\Service;

use Flagify\Support\Json;

final class SnapshotService
{
    public const SCHEMA_VERSION = '2026-03-23.v1';

    public function finalize(array $snapshot): array
    {
        $canonical = $snapshot;
        if (isset($canonical['meta']) && is_array($canonical['meta'])) {
            unset($canonical['meta']['generated_at']);
        }

        $checksum = hash('sha256', Json::canonicalEncode($canonical));
        $snapshot['schema_version'] = self::SCHEMA_VERSION;
        $snapshot['meta']['schema_version'] = self::SCHEMA_VERSION;
        $snapshot['meta']['snapshot_checksum'] = $checksum;

        return [
            'payload' => $snapshot,
            'checksum' => $checksum,
        ];
    }
}
