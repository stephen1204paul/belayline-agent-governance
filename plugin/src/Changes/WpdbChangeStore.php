<?php

declare(strict_types=1);

namespace Specflux\AgentSafety\Plugin\Changes;

use Specflux\AgentSafety\Changes\ChangeAction;
use Specflux\AgentSafety\Changes\ChangeLedger;
use Specflux\AgentSafety\Changes\ClosedWindow;
use Specflux\AgentSafety\Changes\StoredChange;
use Specflux\AgentSafety\Plugin\Support\Schema;
use wpdb;

/**
 * Storage for the `agsafe_changes` table ({@see Schema::changesColumns()}).
 * Unlike the audit log this table is NOT append-only: it sits outside the hash
 * chain by design, so the retention purge and the privacy eraser can delete
 * rows from it.
 */
final class WpdbChangeStore implements ChangeLedger
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

    public function forEvent(string $eventId): array
    {
        $table = $this->table();

        return $this->load(
            // phpcs:ignore WordPress.DB.PreparedSQL -- trusted internal table name.
            $this->db->prepare("SELECT * FROM {$table} WHERE event_id = %s ORDER BY seq ASC", $eventId)
        );
    }

    public function forCorrelation(string $correlationId): array
    {
        $table = $this->table();

        return $this->load(
            // phpcs:ignore WordPress.DB.PreparedSQL -- trusted internal table name.
            $this->db->prepare("SELECT * FROM {$table} WHERE correlation_id = %s ORDER BY id ASC", $correlationId)
        );
    }

    public function recentCorrelations(int $limit, int $offset = 0): array
    {
        $table = $this->table();
        $rows = $this->db->get_col(
            // phpcs:ignore WordPress.DB.PreparedSQL -- trusted internal table name.
            $this->db->prepare("SELECT correlation_id FROM {$table} GROUP BY correlation_id ORDER BY MAX(id) DESC LIMIT %d OFFSET %d", $limit, $offset)
        );

        return is_array($rows) ? array_values(array_map('strval', $rows)) : [];
    }

    public function find(int $id): ?StoredChange
    {
        $table = $this->table();

        // phpcs:ignore WordPress.DB.PreparedSQL -- trusted internal table name.
        return $this->load($this->db->prepare("SELECT * FROM {$table} WHERE id = %d", $id))[0] ?? null;
    }

    /** Stamp rows as undone by `$eventId`; a row already stamped is left alone. */
    public function markRestored(array $ids, string $eventId): void
    {
        if ($ids === []) {
            return;
        }

        $table = $this->table();
        $placeholders = implode(',', array_fill(0, count($ids), '%d'));
        $this->db->query(
            // phpcs:ignore WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders -- trusted table name; placeholder list built from a count.
            $this->db->prepare("UPDATE {$table} SET restored_by_event = %s WHERE restored_by_event IS NULL AND id IN ({$placeholders})", array_merge([$eventId], array_map('intval', $ids)))
        );
    }

    /** @return list<StoredChange> */
    private function load(?string $sql): array
    {
        if ($sql === null) {
            return [];
        }
        $rows = $this->db->get_results($sql, ARRAY_A);
        $changes = [];
        foreach (is_array($rows) ? $rows : [] as $row) {
            $changes[] = self::hydrate((array) $row);
        }

        return $changes;
    }

    /** @param array<string, mixed> $row */
    private static function hydrate(array $row): StoredChange
    {
        $okBefore = $okAfter = true;
        $before = self::decode($row['before_json'] ?? null, $okBefore);
        $after = self::decode($row['after_json'] ?? null, $okAfter);
        $fields = json_decode((string) ($row['changed_fields'] ?? '[]'), true);
        $restorable = (int) ($row['restorable'] ?? 0) === 1 && $okBefore && $okAfter;

        return new StoredChange(
            (int) $row['id'],
            (string) $row['event_id'],
            (string) $row['correlation_id'],
            (int) $row['seq'],
            (string) $row['object_type'],
            (string) $row['object_id'],
            isset($row['object_subtype']) ? (string) $row['object_subtype'] : null,
            ChangeAction::tryFrom((string) $row['action']) ?? ChangeAction::Irreversible,
            $before,
            $after,
            is_array($fields) ? array_values(array_map('strval', $fields)) : [],
            $restorable,
            isset($row['restorable_reason']) ? (string) $row['restorable_reason'] : ($restorable ? null : 'unencodable'),
            isset($row['restored_by_event']) && $row['restored_by_event'] !== '' ? (string) $row['restored_by_event'] : null,
            isset($row['ts']) ? (string) $row['ts'] : null,
        );
    }

    /**
     * @param mixed $json
     * @param-out bool $ok
     * @return array<string, mixed>|null
     */
    private static function decode($json, bool &$ok): ?array
    {
        $ok = true;
        if ($json === null) {
            return null;
        }
        $data = json_decode((string) $json, true);
        if (!is_array($data)) {
            $ok = false;

            return null;
        }

        return $data;
    }
}
