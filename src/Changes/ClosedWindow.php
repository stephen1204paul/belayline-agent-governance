<?php

declare(strict_types=1);

namespace Specflux\AgentSafety\Changes;

/** What a {@see CaptureWindow} hands back when its outermost call returns. */
final class ClosedWindow
{
    /** @param list<Change> $changes */
    public function __construct(
        public readonly string $eventId,
        public readonly string $correlationId,
        public readonly array $changes,
    ) {
    }

    public function sha256(): string
    {
        return ChangeSet::hash($this->changes);
    }
}
