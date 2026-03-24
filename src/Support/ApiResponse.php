<?php

declare(strict_types=1);

namespace Flagify\Support;

final class ApiResponse
{
    public function __construct(
        private readonly int $status,
        private readonly ?array $payload,
        private readonly array $headers = []
    ) {
    }

    public function payload(): ?array
    {
        return $this->payload;
    }

    public function emit(): void
    {
        http_response_code($this->status);
        foreach ($this->headers as $name => $value) {
            header(sprintf('%s: %s', $name, $value));
        }

        if ($this->status !== 304) {
            header('Content-Type: application/json');
        }

        if ($this->payload === null || $this->status === 204 || $this->status === 304) {
            return;
        }

        echo Json::encode($this->payload);
    }
}
