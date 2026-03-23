<?php

declare(strict_types=1);

namespace Flagify\Support;

use RuntimeException;

class ApiError extends RuntimeException
{
    public function __construct(
        private readonly string $codeName,
        string $message,
        private readonly int $status,
        private readonly array $details = []
    ) {
        parent::__construct($message);
    }

    public function codeName(): string
    {
        return $this->codeName;
    }

    public function status(): int
    {
        return $this->status;
    }

    public function details(): array
    {
        return $this->details;
    }
}
