<?php

declare(strict_types=1);

namespace Flagify\Service;

final class EvaluationContextService
{
    public function __construct(private readonly IdentityService $identities)
    {
    }

    public function fromClient(string $projectId, array $client, array $transientTraits = []): array
    {
        $identity = $this->identities->ensureClientIdentity($projectId, $client);
        $persistedTraits = $this->identities->persistedTraitsForIdentity($identity);
        $effectiveTraits = $this->identities->effectiveTraits($identity, $client, $transientTraits);

        return [
            'identity' => $identity,
            'subject' => [
                'key' => $client['key'],
                'kind' => 'client',
                'identifier' => $identity['identifier'],
                'traits' => $effectiveTraits,
                'metadata' => $effectiveTraits,
                'client' => [
                    'id' => $client['id'],
                    'key' => $client['key'],
                    'metadata' => $effectiveTraits,
                ],
            ],
            'persisted_traits' => $persistedTraits,
            'effective_traits' => $effectiveTraits,
            'transient_traits' => $transientTraits,
            'client' => $client,
        ];
    }

    public function fromIdentity(array $identity, array $transientTraits = [], ?array $client = null): array
    {
        $persistedTraits = $this->identities->persistedTraitsForIdentity($identity);
        $effectiveTraits = $this->identities->effectiveTraits($identity, $client, $transientTraits);

        $subject = [
            'key' => $client['key'] ?? $identity['identifier'],
            'kind' => $identity['kind'],
            'identifier' => $identity['identifier'],
            'traits' => $effectiveTraits,
            'metadata' => $effectiveTraits,
        ];

        if ($client !== null) {
            $subject['client'] = [
                'id' => $client['id'],
                'key' => $client['key'],
                'metadata' => $effectiveTraits,
            ];
        }

        return [
            'identity' => $identity,
            'subject' => $subject,
            'persisted_traits' => $persistedTraits,
            'effective_traits' => $effectiveTraits,
            'transient_traits' => $transientTraits,
            'client' => $client,
        ];
    }
}
