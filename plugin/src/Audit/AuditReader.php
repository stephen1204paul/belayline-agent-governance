<?php

declare(strict_types=1);

namespace Belayline\Plugin\Audit;

use Belayline\Audit\HashChain;
use Belayline\Plugin\Admin\Map\CallOutcome;
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
     * Newest-first page of rows matching a filter.
     *
     * @return list<array<string, mixed>>
     */
    public function filtered(AuditFilter $f, int $limit, int $offset, int $now): array
    {
        [$from, $where, $args] = $this->clauses($f, $now);
        $rows = $this->db->get_results(
            $this->db->prepare(
                'SELECT l.*' . $from . $where . ' ORDER BY l.id DESC LIMIT %d OFFSET %d',
                ...[...$args, $limit, $offset]
            ),
            ARRAY_A
        );

        return is_array($rows) ? $rows : [];
    }

    public function countFiltered(AuditFilter $f, int $now): int
    {
        [$from, $where, $args] = $this->clauses($f, $now);

        return (int) $this->db->get_var($this->db->prepare('SELECT COUNT(*)' . $from . $where, ...$args));
    }

    /**
     * The FROM, WHERE and placeholder values shared by {@see filtered()} and
     * {@see countFiltered()}, built from the ACTIVE filters only: an empty filter
     * has no WHERE at all, so `ORDER BY l.id DESC LIMIT` walks the primary key.
     * Only fixed template fragments are concatenated; every value is a placeholder.
     * The approvals table is joined only for the outcome filter (the one place the
     * outcome expression, {@see CallOutcome::SQL}, reads `a.*`); the page itself
     * only ever selects `l.*`.
     *
     * @return array{0: string, 1: string, 2: list<string|int>}
     */
    private function clauses(AuditFilter $f, int $now): array
    {
        $esc = fn (string $v): string => $this->db->esc_like($v);
        $conds = [];
        $args = [];
        $table = $this->table();
        $from = ' FROM %i l';
        $fromArgs = [$table];

        if ($f->days > 0) {
            $conds[] = 'l.ts >= %s';
            $args[] = gmdate('Y-m-d\TH:i:s\Z', $now - $f->days * 86400);
        }
        if ($f->pack !== '') {
            $conds[] = 'l.pack = %s';
            $args[] = $f->pack === '__none' ? '' : $f->pack;
        }
        if ($f->through !== '') {
            $conds[] = 'l.ability LIKE %s';
            $args[] = $esc($f->through) . '/%';
        }
        // Who made a call: the principal (`actor.token_id`) or the agent credential
        // (`actor.via`). Matched with a REGEXP ANCHORED at the start of record_json and
        // walking the server-controlled fields in canonical order, with quote-bounded
        // classes that cannot cross a string. A LIKE anywhere in the row would also hit
        // KEYS an agent sends inside `input` (e.g. {"actor":{"token_id":"x"}}), because
        // only string VALUES are escaped there, not object keys.
        $patterns = [];
        if ($f->tokenPrefix !== '') {
            $rx = self::tokenRegex($f->tokenPrefix);
            $patterns = $rx === null ? [] : [self::actorRegex($rx, false, true), self::actorRegex($rx, true, true)];
        } else {
            foreach ($f->tokens as $t) {
                $rx = self::tokenRegex($t);
                if ($rx !== null) {
                    $patterns[] = self::actorRegex($rx, false, false);
                    $patterns[] = self::actorRegex($rx, true, false);
                }
            }
        }
        if ($f->tokens !== [] || $f->tokenPrefix !== '') {
            if ($patterns === []) {
                $conds[] = '1 = 0'; // a token outside the safe charset matches nothing (fail closed)
            } else {
                $patterns = array_slice($patterns, 0, AuditFilter::TOKEN_SLOTS * 2);
                $conds[] = '(' . implode(' OR ', array_fill(0, count($patterns), 'l.record_json REGEXP %s')) . ')';
                array_push($args, ...$patterns);
            }
        }
        if ($f->outcome !== '') {
            $from .= ' LEFT JOIN %i a ON a.audit_event_id = l.event_id';
            $fromArgs[] = Schema::approvalsTable($this->db);
            $conds[] = CallOutcome::SQL . ' = %s';
            array_push($args, ...CallOutcome::tableArgs($table));
            $args[] = $f->outcome;
        }

        return [$from, $conds === [] ? '' : ' WHERE ' . implode(' AND ', $conds), [...$fromArgs, ...$args]];
    }

    /**
     * Distinct pack names present in the log, for the filter dropdown.
     *
     * @return list<string>
     */
    public function packs(): array
    {
        $rows = $this->db->get_results($this->db->prepare('SELECT DISTINCT pack FROM %i ORDER BY pack', $this->table()), ARRAY_A);

        return array_values(array_filter(array_map(
            static fn (array $r): string => (string) ($r['pack'] ?? ''),
            is_array($rows) ? $rows : []
        ), static fn (string $p): bool => $p !== ''));
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
                    'SELECT id, prev_hash, record_json, entry_hash, approval_id FROM %i WHERE id > %d ORDER BY id ASC LIMIT %d',
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

            // The indexed approval_id column is only a query aid: the hashed record
            // is the truth, so a column that disagrees with it was edited.
            foreach ($rows as $r) {
                if (!self::approvalColumnMatchesRecord($r['approval_id'] ?? null, (string) $r['record_json'])) {
                    return false;
                }
            }

            $last = $rows[count($rows) - 1];
            $anchor = (string) $last['entry_hash'];
            $afterId = (int) $last['id'];
        } while (count($rows) === $limit);

        return true;
    }

    /** Does the `approval_id` column equal the (hashed) record's top-level `approval_id`? Both absent counts as equal. */
    private static function approvalColumnMatchesRecord(mixed $column, string $recordJson): bool
    {
        $column = is_string($column) && $column !== '' ? $column : null;

        $fromRecord = null;
        // Cheap precheck: a record without the key anywhere cannot carry one at the top level.
        if (str_contains($recordJson, '"approval_id":')) {
            $decoded = json_decode($recordJson, true);
            if (!is_array($decoded)) {
                return false;
            }
            $value = $decoded['approval_id'] ?? null;
            if ($value !== null && !is_string($value)) {
                return false;
            }
            $fromRecord = $value !== '' ? $value : null;
        }

        return $column === $fromRecord;
    }

    /**
     * A token as a regex fragment portable across MySQL 8 (ICU), MySQL 5.7 and
     * MariaDB (PCRE), or null when it holds anything outside the charset real
     * identity tokens use (letters, digits, `: _ . @ / -`). Only `.` is a
     * metacharacter in that set, written as the `[.]` bracket class; the rest are
     * literal outside brackets in every dialect.
     */
    private static function tokenRegex(string $token): ?string
    {
        if ($token === '' || preg_match('/^[A-Za-z0-9:_.@\/-]+$/D', $token) !== 1) {
            return null;
        }

        return str_replace('.', '[.]', $token);
    }

    /**
     * The anchored pattern for one token. `record_json` always begins
     * `{"id":..,"ts":..,"correlation_id":..,"pack":..,"actor":{"token_id":..,"wp_user":..`
     * (then, on newer rows, `"env_type":..,"env_mismatch":..`, then `"via":..` only when
     * present). `$rx` comes from {@see tokenRegex()}.
     */
    private static function actorRegex(string $rx, bool $via, bool $prefix): string
    {
        $head = '^[{]"id":"[^"]*","ts":"[^"]*","correlation_id":"[^"]*","pack":"[^"]*","actor":[{]"token_id":';
        if (!$via) {
            return $head . '"' . $rx . ($prefix ? '' : '"');
        }

        return $head . '(null|"[^"]*"),"wp_user":(null|[0-9]+)(,"env_type":"[^"]*","env_mismatch":(true|false))?,"via":"'
            . $rx . ($prefix ? '' : '"[}]');
    }
}
