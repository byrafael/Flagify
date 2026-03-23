<?php

declare(strict_types=1);

namespace Flagify\Support;

final class ApiResponse
{
    public function __construct(
        private readonly int $status,
        private readonly ?array $payload
    ) {
    }

    public function payload(): ?array
    {
        return $this->payload;
    }

    public function emit(): void
    {
        http_response_code($this->status);
        header('Content-Type: application/json');

        if ($this->payload === null || $this->status === 204) {
            return;
        }

        echo Json::encode($this->payload);
    }
}
