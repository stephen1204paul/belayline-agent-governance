<?php

declare(strict_types=1);

namespace Belayline\Plugin\Audit;

use Belayline\Audit\HashChain;
use wpdb;

/**
 * Read side of the audit log: paged listing, totals, full export, and a chain
 * integrity check that runs the stored rows back through {@see HashChain::verify()}.
 * Read-only — never mutates the append-only table.
 */
final class AuditReader
{
    public function __construct(private readonly wpdb $db)
    {
    }

    public function table(): string
    {
        return $this->db->prefix . 'belayline_audit_log';
    }

    public function total(): int
    {
        // phpcs:ignore WordPress.DB.PreparedSQL -- trusted internal table name.
        return (int) $this->db->get_var("SELECT COUNT(*) FROM {$this->table()}");
    }

    /**
     * Newest-first page for the admin table.
     *
     * @return list<array<string, mixed>>
     */
    public function latest(int $limit, int $offset = 0): array
    {
        $table = $this->table();
        $rows = $this->db->get_results(
            // phpcs:ignore WordPress.DB.PreparedSQL -- trusted internal table name.
            $this->db->prepare("SELECT * FROM {$table} ORDER BY id DESC LIMIT %d OFFSET %d", $limit, $offset),
            ARRAY_A
        );

        return is_array($rows) ? $rows : [];
    }

    /**
     * Audit rows for the given event ids (the Changes screen labels each event
     * with its actor and ability), keyed by event id.
     *
     * @param list<string> $eventIds
     * @return array<string, array<string, mixed>>
     */
    public function forEvents(array $eventIds): array
    {
        if ($eventIds === []) {
            return [];
        }
        $table = $this->table();
        $placeholders = implode(',', array_fill(0, count($eventIds), '%s'));
        $rows = $this->db->get_results(
            // phpcs:ignore WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders -- trusted table name; placeholder list built from a count.
            $this->db->prepare("SELECT * FROM {$table} WHERE event_id IN ({$placeholders})", $eventIds),
            ARRAY_A
        );

        $byEvent = [];
        foreach (is_array($rows) ? $rows : [] as $row) {
            $byEvent[(string) ($row['event_id'] ?? '')] = $row;
        }

        return $byEvent;
    }

    /**
     * All rows oldest-first, for export.
     *
     * @return list<array<string, mixed>>
     */
    public function all(): array
    {
        // phpcs:ignore WordPress.DB.PreparedSQL -- trusted internal table name.
        $rows = $this->db->get_results("SELECT * FROM {$this->table()} ORDER BY id", ARRAY_A);

        return is_array($rows) ? $rows : [];
    }

    /** Re-verify the whole chain from GENESIS. False = a row was altered or deleted. */
    public function verifyChain(): bool
    {
        $table = $this->table();
        // phpcs:ignore WordPress.DB.PreparedSQL -- trusted internal table name.
        $rows = $this->db->get_results("SELECT prev_hash, record_json, entry_hash FROM {$table} ORDER BY id", ARRAY_A);
        if (!is_array($rows)) {
            return true; // empty log is trivially valid
        }

        $entries = array_map(static fn ($r) => [
            'prev_hash'      => (string) $r['prev_hash'],
            'canonical_json' => (string) $r['record_json'],
            'entry_hash'     => (string) $r['entry_hash'],
        ], $rows);

        return HashChain::verify($entries);
    }
}
