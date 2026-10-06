<?php

declare(strict_types=1);

namespace Specflux\AgentSafety\Changes;

/** The outcome for one change in an undo. */
final class UndoItem
{
    public const RESTORED = 'restored';
    /** Preview only: every check passed, an undo would restore it. */
    public const RESTORABLE = 'restorable';
    /** Never restorable (irreversible, truncated, ...): skipped, the undo goes on. */
    public const SKIPPED = 'skipped';
    /** Drift, missing object or a platform error: the undo stopped here. */
    public const FAILED = 'failed';
    /** After a failure: not attempted. */
    public const PENDING = 'pending';

    public const REASON_DRIFT = 'drift';
    public const REASON_OBJECT_MISSING = 'object_missing';
    public const REASON_RESTORE_FAILED = 'restore_failed';
    public const REASON_NO_ADAPTER = 'no_adapter';

    /** @param list<array{field: string, before: mixed, after: mixed, current: mixed}> $drift */
    public function __construct(
        public readonly int $changeId,
        public readonly string $objectType,
        public readonly string $objectId,
        public readonly string $status,
        public readonly ?string $reason = null,
        public readonly array $drift = [],
        public readonly ?string $message = null,
    ) {
    }
}
