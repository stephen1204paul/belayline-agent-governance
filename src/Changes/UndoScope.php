<?php

declare(strict_types=1);

namespace Belayline\Changes;

/** What one undo covers: one change, one audit event, or one correlation id ("undo this run"). */
final class UndoScope
{
    public const CHANGE = 'change';
    public const EVENT = 'event';
    public const CORRELATION = 'correlation';

    private function __construct(public readonly string $kind, public readonly string $id)
    {
    }

    public static function change(int $id): self
    {
        return new self(self::CHANGE, (string) $id);
    }

    public static function event(string $eventId): self
    {
        return new self(self::EVENT, $eventId);
    }

    public static function correlation(string $correlationId): self
    {
        return new self(self::CORRELATION, $correlationId);
    }
}
