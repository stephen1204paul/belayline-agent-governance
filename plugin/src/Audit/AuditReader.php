<?php

declare(strict_types=1);

namespace Belayline\Plugin\Audit;

use Belayline\Audit\HashChain;
use Belayline\Plugin\Support\Schema;
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

    /**
     * The latest retention checkpoint, or null if the log was never pruned.
     *
     * @return array{id: int, created_ts: string, pruned_through_id: int, pruned_through_entry_hash: string, pruned_count: int}|null
     */
    public function checkpoint(): ?array
    {
        $row = $this->db->get_row(
            $this->db->prepare('SELECT * FROM %i ORDER BY id DESC LIMIT 1', Schema::checkpointTable($this->db)),
            ARRAY_A
        );
        if (!is_array($row) || !isset($row['pruned_through_entry_hash'])) {
            return null;
        }

        return [
            'id' => (int) ($row['id'] ?? 0),
            'created_ts' => (string) ($row['created_ts'] ?? ''),
            'pruned_through_id' => (int) ($row['pruned_through_id'] ?? 0),
            'pruned_through_entry_hash' => (string) $row['pruned_through_entry_hash'],
            'pruned_count' => (int) ($row['pruned_count'] ?? 0),
        ];
    }

    /**
     * Rows with id above $afterId, oldest first, optionally bounded by `ts`
     * (inclusive $fromTs, exclusive $beforeTs; ISO-8601 UTC strings compare
     * lexicographically). The keyset paging the export and the chain check use
     * to stream the table without loading it.
     *
     * @return list<array<string, mixed>>
     */
    public function after(int $afterId, int $limit, string $fromTs = '', string $beforeTs = '9999-12-31'): array
    {
        $rows = $this->db->get_results(
            $this->db->prepare(
                'SELECT * FROM %i WHERE id > %d AND ts >= %s AND ts < %s ORDER BY id ASC LIMIT %d',
                $this->table(),
                $afterId,
                $fromTs,
                $beforeTs,
                $limit
            ),
            ARRAY_A
        );

        return is_array($rows) ? $rows : [];
    }

    /**
     * Re-verify the chain. A never-pruned log starts at GENESIS; after a prune
     * it starts at the latest checkpoint: the first surviving row's prev_hash
     * must equal the checkpoint's pruned_through_entry_hash. Rows at or below
     * the checkpointed id (left behind by an interrupted prune) are ignored.
     * False = a row was altered or deleted, or the checkpoint no longer joins
     * onto the rows that follow it. Streams in batches, so memory stays flat.
     */
    public function verifyChain(): bool
    {
        $table = $this->table();
        $checkpoint = $this->checkpoint();
        $anchor = $checkpoint !== null ? $checkpoint['pruned_through_entry_hash'] : HashChain::GENESIS;
        $afterId = $checkpoint !== null ? $checkpoint['pruned_through_id'] : 0;
        $limit = 500;

        do {
            $rows = $this->db->get_results(
                $this->db->prepare(
                    'SELECT id, prev_hash, record_json, entry_hash FROM %i WHERE id > %d ORDER BY id ASC LIMIT %d',
                    $table,
                    $afterId,
                    $limit
                ),
                ARRAY_A
            );
            if (!is_array($rows) || $rows === []) {
                break; // empty log (or nothing past the checkpoint) is trivially valid
            }

            $entries = array_map(static fn ($r) => [
                'prev_hash'      => (string) $r['prev_hash'],
                'canonical_json' => (string) $r['record_json'],
                'entry_hash'     => (string) $r['entry_hash'],
            ], $rows);

            if (!HashChain::verify($entries, $anchor)) {
                return false;
            }

            $last = $rows[count($rows) - 1];
            $anchor = (string) $last['entry_hash'];
            $afterId = (int) $last['id'];
        } while (count($rows) === $limit);

        return true;
    }
}
