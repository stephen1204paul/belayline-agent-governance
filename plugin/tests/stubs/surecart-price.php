<?php

declare(strict_types=1);

namespace SureCart\Models;

/**
 * Minimal stand-in for SureCart's model request layer: records the query and
 * returns canned data. No network.
 */
class Price
{
    /** @var list<array<string, mixed>> */
    public static array $queries = [];
    public static mixed $result = null;

    /** @param array<string, mixed> $q */
    public static function where(array $q): static
    {
        self::$queries[] = $q;

        return new static();
    }

    public function find(string $id): mixed
    {
        self::$queries[] = ['find' => $id];

        return self::$result;
    }
}
