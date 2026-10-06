<?php

declare(strict_types=1);

namespace Specflux\AgentSafety\Changes;

/**
 * The canonical form of the changes recorded for one audit event. Its hash is
 * the `changes_sha256` an audit record carries, so the audit hash chain covers
 * WHAT was recorded while the snapshot bodies stay outside the chain (and can
 * be purged or erased).
 *
 * An empty set hashes like any other: an event with a window but no recorded
 * change ("No changes recorded") is therefore distinguishable from an event
 * that never had a window (no `changes_sha256` at all).
 */
final class ChangeSet
{
    /** @param list<Change> $changes In recording order; position + 1 is the row's `seq`. */
    public static function hash(array $changes): string
    {
        return hash('sha256', self::canonicalJson($changes));
    }

    /** @param list<Change> $changes */
    public static function canonicalJson(array $changes): string
    {
        $rows = [];
        foreach (array_values($changes) as $i => $change) {
            $rows[] = $change->toRow($i + 1);
        }

        return (string) json_encode($rows, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
