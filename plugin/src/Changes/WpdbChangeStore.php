<?php

declare(strict_types=1);

namespace Specflux\AgentSafety\Plugin\Changes;

use Specflux\AgentSafety\Changes\ClosedWindow;
use Specflux\AgentSafety\Plugin\Support\Schema;
use wpdb;

/**
 * Storage for the `agsafe_changes` table ({@see Schema::changesColumns()}).
 * Unlike the audit log this table is NOT append-only: it sits outside the hash
 * chain by design, so the retention purge and the privacy eraser can delete
 * rows from it.
 */
final class WpdbChangeStore
{
    public function __construct(private readonly wpdb $db)
    {
    }

    public function table(): string
    {
        return Schema::changesTable($this->db);
    }

    /**
     * Persist every change of a closed window as one row each, `seq` being its
     * 1-based position (the same position {@see \Specflux\AgentSafety\Changes\ChangeSet::hash()}
     * commits to). A window with no changes writes nothing: "no changes
     * recorded" is zero rows for the event.
     *
     * @param string $ts UTC `Y-m-d H:i:s`.
     */
    public function insertWindow(ClosedWindow $window, string $ts): void
    {
        foreach ($window->changes as $i => $change) {
            $row = $change->toRow($i + 1);
            $this->db->insert(
                $this->table(),
                [
                    'event_id' => $window->eventId,
                    'correlation_id' => $window->correlationId,
                    'seq' => $row['seq'],
                    'object_type' => $row['object_type'],
                    'object_id' => $row['object_id'],
                    'object_subtype' => $row['object_subtype'],
                    'action' => $row['action'],
                    'before_json' => $row['before_json'],
                    'after_json' => $row['after_json'],
                    'changed_fields' => $row['changed_fields'],
                    'restorable' => $row['restorable'],
                    'restorable_reason' => $row['restorable_reason'],
                    'ts' => $ts,
                ],
                ['%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s'],
            );
        }
    }

    /** Delete every row recorded before the UTC cutoff (`Y-m-d H:i:s`). Returns rows deleted. */
    public function purgeOlderThan(string $cutoff): int
    {
        $table = $this->table();
        $deleted = $this->db->query(
            // phpcs:ignore WordPress.DB.PreparedSQL -- trusted internal table name.
            $this->db->prepare("DELETE FROM {$table} WHERE ts < %s", $cutoff)
        );

        return is_int($deleted) ? $deleted : 0;
    }
}
