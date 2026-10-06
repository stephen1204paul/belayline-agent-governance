<?php

declare(strict_types=1);

namespace Specflux\AgentSafety\Changes;

/**
 * Capture for platforms that WordPress hooks cannot see (query-builder writes
 * with no do_action, remote APIs with only synced local copies). It is ARGS
 * based: at window open the host asks {@see refs()} which objects the call
 * will touch, snapshots each BEFORE the call and again AFTER, and records one
 * Change for every ref whose state differs. A ref that did not change records
 * nothing.
 *
 * Precedence: for every ref a snapshotter declared (and could snapshot
 * before), the snapshot wins. {@see CaptureWindow::claim()} makes the window
 * ignore hook-recorded changes for the same (object_type, object_id), so a
 * hook adapter and a snapshotter never double-record one object.
 *
 * Snapshotters only observe; restoring goes through the ordinary
 * {@see ChangeRestoreAdapter} for the same object type. A snapshotter that
 * throws never changes the call's outcome: the host records a `capture_error`
 * marker change instead.
 */
interface ChangeSnapshotter
{
    /** Whether this snapshotter handles the ability (the full ability id). */
    public function handles(string $ability): bool;

    /**
     * The objects a call will touch, derived from its input only.
     *
     * @param array<string, mixed> $args
     * @return list<SnapshotRef>
     */
    public function refs(string $ability, array $args): array;

    /**
     * Current state of one object in the same shape its restore adapter's
     * currentState() returns; null when the object does not exist.
     *
     * @return array<string, mixed>|null
     */
    public function snapshot(SnapshotRef $ref): ?array;
}
