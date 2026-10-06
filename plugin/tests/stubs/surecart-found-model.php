<?php

declare(strict_types=1);

namespace SureCart\Models;

/** A hydrated SureCart model as returned by find(): exposes toArray(). */
class FoundModel
{
    /** @param array<string, mixed> $a */
    public function __construct(private array $a)
    {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return $this->a;
    }
}
