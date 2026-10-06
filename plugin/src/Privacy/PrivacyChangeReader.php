<?php

declare(strict_types=1);

namespace Specflux\AgentSafety\Plugin\Privacy;

use Specflux\AgentSafety\Plugin\Support\Schema;
use wpdb;

/**
 * Read/delete side of the change history for the personal-data exporter and
 * eraser. Change rows have no user column of their own: they belong to a user
 * through the audit row sharing their `event_id` (which carries `wp_user`), so
 * callers hand this class the event ids of ONE user's audit rows
 * ({@see PrivacyAuditReader::pageForUser()}) and never a free-text match.
 */
final class PrivacyChangeReader
{
    public function __construct(private readonly wpdb $db)
    {
    }

    public function table(): string
    {
        return Schema::changesTable($this->db);
    }

    /**
     * @param list<string> $eventIds
     * @return list<array<string, mixed>>
     */
    public function forEvents(array $eventIds): array
    {
        if ($eventIds === []) {
            return [];
        }

        $table = $this->table();
        $rows = $this->db->get_results(
            // phpcs:ignore WordPress.DB.PreparedSQL -- trusted internal table name; one %s placeholder per id.
            $this->db->prepare("SELECT * FROM {$table} WHERE event_id IN (" . self::placeholders($eventIds) . ') ORDER BY id', $eventIds),
            ARRAY_A
        );

        return is_array($rows) ? $rows : [];
    }

    /**
     * @param list<string> $eventIds
     * @return int Rows deleted.
     */
    public function deleteForEvents(array $eventIds): int
    {
        if ($eventIds === []) {
            return 0;
        }

        $table = $this->table();
        $deleted = $this->db->query(
            // phpcs:ignore WordPress.DB.PreparedSQL -- trusted internal table name; one %s placeholder per id.
            $this->db->prepare("DELETE FROM {$table} WHERE event_id IN (" . self::placeholders($eventIds) . ')', $eventIds)
        );

        return is_int($deleted) ? $deleted : 0;
    }

    /**
     * Event ids of a page of audit rows.
     *
     * @param list<array<string, mixed>> $auditRows
     * @return list<string>
     */
    public static function eventIds(array $auditRows): array
    {
        $ids = [];
        foreach ($auditRows as $row) {
            $id = (string) ($row['event_id'] ?? '');
            if ($id !== '') {
                $ids[] = $id;
            }
        }

        return $ids;
    }

    /** @param list<string> $ids */
    private static function placeholders(array $ids): string
    {
        return implode(', ', array_fill(0, count($ids), '%s'));
    }
}
