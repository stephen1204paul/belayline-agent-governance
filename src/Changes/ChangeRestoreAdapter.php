<?php

declare(strict_types=1);

namespace Specflux\AgentSafety\Changes;

/**
 * Restores recorded changes of one or more object types, writing through the
 * platform's own APIs (never raw SQL). Adapters hold no policy about scope,
 * ordering or audit: the undo engine owns those and calls these four hooks per
 * change, in that order: capability, canRestore, currentState (drift check),
 * restore.
 */
interface ChangeRestoreAdapter
{
    /** @return list<string> object types this adapter restores. */
    public function objectTypes(): array;

    /** Capability the human must hold to undo this change (`manage_options`, `manage_woocommerce`, ...). */
    public function capability(StoredChange $change): string;

    /** Why this change can never be undone, as a reason code; null when it can. */
    public function canRestore(StoredChange $change): ?string;

    /**
     * The object's live state in the same shape as the recorded before/after
     * bodies; null when the object no longer exists.
     *
     * @return array<string, mixed>|null
     */
    public function currentState(StoredChange $change): ?array;

    /**
     * Fields that change as a side effect of any write (modified timestamps,
     * counters). They are excluded from the drift check and never written back.
     *
     * @return list<string>
     */
    public function volatileFields(): array;

    /**
     * Apply the inverse of the change. The drift check has already passed.
     *
     * @throws \Throwable When the platform refuses; the engine stops and reports.
     */
    public function restore(StoredChange $change): void;
}
