<?php

declare(strict_types=1);

namespace Specflux\AgentSafety\Plugin\Changes;

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
