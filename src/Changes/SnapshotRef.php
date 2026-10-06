<?php

declare(strict_types=1);

namespace Specflux\AgentSafety\Changes;

/** One object a {@see ChangeSnapshotter} says a call will touch. */
final class SnapshotRef
{
    public function __construct(
        public readonly string $objectType,
        public readonly string $objectId,
        public readonly ?string $objectSubtype = null,
    ) {
    }

    public function key(): string
    {
        return $this->objectType . "\0" . $this->objectId;
    }
}
