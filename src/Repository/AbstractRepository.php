<?php

declare(strict_types=1);

namespace Flagify\Repository;

use Flagify\Support\Json;
use PDO;

abstract class AbstractRepository
{
    public function __construct(protected readonly PDO $pdo)
    {
    }

    protected function decodeJsonFields(array $row, array $fields): array
    {
        foreach ($fields as $field) {
            if (array_key_exists($field, $row) && is_string($row[$field])) {
                $row[$field] = Json::decode($row[$field]);
            }
        }

        return $row;
    }
}
