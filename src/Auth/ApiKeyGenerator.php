<?php

declare(strict_types=1);

namespace Flagify\Auth;

final class ApiKeyGenerator
{
    public function generate(): array
    {
        $prefix = 'flg_pk_' . bin2hex(random_bytes(4));
        $secret = $prefix . '.' . bin2hex(random_bytes(24));

        return [
            'prefix' => $prefix,
            'secret' => $secret,
            'secret_hash' => password_hash($secret, PASSWORD_ARGON2ID),
        ];
    }
}
