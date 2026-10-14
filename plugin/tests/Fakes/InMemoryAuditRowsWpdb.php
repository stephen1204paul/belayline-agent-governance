<?php

declare(strict_types=1);

/**
 * A wpdb stand-in that actually behaves like the audit-log table for the
 * queries {@see \Belayline\Plugin\Privacy\PrivacyAuditReader} and
 * {@see \Belayline\Plugin\Audit\AuditReader} issue against it,
 * instead of the base test stub's canned-return behaviour (tests/stubs/wpdb.php).
 * Both classes issue only a handful of recognisable query shapes, so this
 * fake recognises them from the rendered SQL string (wpdb::prepare() already
 * bakes %d in bare and %s quoted) and answers from an in-memory row set —
 * enough to make "does the WHERE actually filter" and "does paging actually
 * page" real, failable assertions instead of assertions on canned output.
 */
final class InMemoryAuditRowsWpdb extends wpdb
{
    /** @var list<array<string, mixed>> */
    private array $rows = [];

    /** @var list<array<string, mixed>> */
    private array $changeRows = [];

    /** @param list<array<string, mixed>> $rows Row shape: id, event_id, ts, ... (the `belayline_changes` columns). */
    public function seedChanges(array $rows): void
    {
        $this->changeRows = $rows;
    }

    /** @return list<array<string, mixed>> */
    public function changeRows(): array
    {
        return $this->changeRows;
    }

    /** @param list<array<string, mixed>> $rows Row shape: id, wp_user, prev_hash, record_json, entry_hash, ... */
    public function seed(array $rows): void
    {
        $this->rows = $rows;
    }

    public function query(string $query): int|bool
    {
        if (preg_match('/^DELETE FROM wp_belayline_changes WHERE event_id IN \((.*)\)$/s', $query, $m) === 1) {
            $ids = self::quotedList($m[1]);
            $before = count($this->changeRows);
            $this->changeRows = array_values(array_filter(
                $this->changeRows,
                static fn (array $r): bool => !in_array($r['event_id'], $ids, true)
            ));

            return $before - count($this->changeRows);
        }

        if (preg_match("/^DELETE FROM wp_belayline_changes WHERE ts < '([^']*)'$/", $query, $m) === 1) {
            $before = count($this->changeRows);
            $this->changeRows = array_values(array_filter(
                $this->changeRows,
                static fn (array $r): bool => (string) $r['ts'] >= $m[1]
            ));

            return $before - count($this->changeRows);
        }

        return parent::query($query);
    }

    /** @return list<string> */
    private static function quotedList(string $sql): array
    {
        preg_match_all("/'([^']*)'/", $sql, $m);

        return $m[1];
    }

    public function get_var(?string $query = null): mixed
    {
        if ($query !== null && preg_match('/SELECT COUNT\(\*\).*WHERE wp_user = (\d+)/s', $query, $m) === 1) {
            $userId = (int) $m[1];

            return count(array_filter($this->rows, static fn (array $r): bool => (int) $r['wp_user'] === $userId));
        }

        return parent::get_var($query);
    }

    /** @return list<array<string, mixed>> */
    public function get_results(?string $query = null, string $output = 'ARRAY_A'): array
    {
        if ($query !== null && preg_match('/FROM wp_belayline_changes WHERE event_id IN \((.*)\) ORDER BY id$/s', $query, $m) === 1) {
            $ids = self::quotedList($m[1]);

            return array_values(array_filter(
                $this->changeRows,
                static fn (array $r): bool => in_array($r['event_id'], $ids, true)
            ));
        }

        if ($query !== null && preg_match('/WHERE wp_user = (\d+).*LIMIT (\d+) OFFSET (\d+)/s', $query, $m) === 1) {
            $userId = (int) $m[1];
            $limit = (int) $m[2];
            $offset = (int) $m[3];

            $matched = array_values(array_filter(
                $this->rows,
                static fn (array $r): bool => (int) $r['wp_user'] === $userId
            ));
            usort($matched, static fn (array $a, array $b): int => $a['id'] <=> $b['id']);

            return array_slice($matched, $offset, $limit);
        }

        if ($query !== null && str_contains($query, 'prev_hash, record_json, entry_hash')) {
            $ordered = $this->rows;
            usort($ordered, static fn (array $a, array $b): int => $a['id'] <=> $b['id']);

            return $ordered;
        }

        return parent::get_results($query, $output);
    }
}
