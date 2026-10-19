<?php

declare(strict_types=1);

namespace Belayline\Plugin\Tests\Fakes;

use PDO;
use wpdb;

/**
 * A wpdb stand-in backed by an in-memory SQLite database, for tests where the
 * behaviour under test lives in the SQL's effect on rows (per-row pending TTLs,
 * retention batches, checkpoint reads) rather than in the SQL text. It creates
 * the three tables those tests touch with SQLite-flavoured DDL, swallows the
 * stores' MySQL `CREATE TABLE IF NOT EXISTS` safety nets, and registers the few
 * MySQL functions the queries call (UTC_TIMESTAMP, GET_LOCK, RELEASE_LOCK).
 * Anything MySQL-only beyond that (DATE_ADD, FOR UPDATE, ...) is out of scope:
 * tests seed such rows directly instead.
 */
final class SqliteWpdb extends wpdb
{
    public readonly PDO $pdo;

    public int $insert_id = 0;

    /** Seconds added to "now" for UTC_TIMESTAMP(), so a test can move time forward. */
    public int $clockOffset = 0;

    public function __construct()
    {
        parent::__construct();
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->sqliteCreateFunction('UTC_TIMESTAMP', fn (): string => gmdate('Y-m-d H:i:s', time() + $this->clockOffset), 0);
        $this->pdo->sqliteCreateFunction('GET_LOCK', static fn (): int => 1);
        $this->pdo->sqliteCreateFunction('RELEASE_LOCK', static fn (): int => 1);
        // MySQL's SUBSTRING_INDEX(str, delim, count): the Overview map query reads the token out of the stored record with it.
        $this->pdo->sqliteCreateFunction('SUBSTRING_INDEX', static function (?string $str, string $delim, int $count): string {
            $str ??= '';
            if ($delim === '' || $count === 0) {
                return '';
            }
            $parts = explode($delim, $str);
            if (abs($count) >= count($parts)) {
                return $str;
            }

            return $count > 0
                ? implode($delim, array_slice($parts, 0, $count))
                : implode($delim, array_slice($parts, $count));
        }, 3);

        $this->pdo->exec('CREATE TABLE wp_belayline_approvals (
            id INTEGER PRIMARY KEY AUTOINCREMENT, approval_id TEXT, verb TEXT, args_hash TEXT, summary TEXT,
            correlation_id TEXT, audit_event_id TEXT, key_id TEXT, status TEXT, fingerprint TEXT,
            fingerprint_kind TEXT, probe_args TEXT, created_ts TEXT, pending_expires_ts TEXT, expires_ts TEXT,
            token_hash TEXT, approver INTEGER, grant_id TEXT, reserved_req TEXT, reserved_ts TEXT, consumed_ts TEXT)');
        $this->pdo->exec('CREATE TABLE wp_belayline_audit_log (
            id INTEGER PRIMARY KEY AUTOINCREMENT, event_id TEXT, ts TEXT, correlation_id TEXT, pack TEXT,
            ability TEXT, tier INTEGER, decision TEXT, result TEXT, wp_user INTEGER, ip TEXT,
            record_json TEXT, prev_hash TEXT, entry_hash TEXT, approval_id TEXT)');
        $this->pdo->exec('CREATE INDEX wp_belayline_audit_log_ts ON wp_belayline_audit_log (ts)');
        $this->pdo->exec('CREATE TABLE wp_belayline_audit_checkpoint (
            id INTEGER PRIMARY KEY AUTOINCREMENT, created_ts TEXT, pruned_through_id INTEGER,
            pruned_through_entry_hash TEXT, pruned_count INTEGER)');
    }

    public function query(string $query): int|bool
    {
        $this->queries[] = $query;
        if (stripos(ltrim($query), 'CREATE TABLE') === 0) {
            return true; // the stores' lazy MySQL DDL: tables already exist here.
        }

        return $this->pdo->exec($query);
    }

    public function get_var(?string $query = null): mixed
    {
        $row = $this->run($query)->fetch(PDO::FETCH_NUM);

        return $row === false ? null : $row[0];
    }

    /** @return array<string, mixed>|null */
    public function get_row(?string $query = null, string $output = 'ARRAY_A'): ?array
    {
        $row = $this->run($query)->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    /** @return list<array<string, mixed>> */
    public function get_results(?string $query = null, string $output = 'ARRAY_A'): array
    {
        return array_values($this->run($query)->fetchAll(PDO::FETCH_ASSOC));
    }

    public function insert(string $table, array $data, ?array $format = null): int
    {
        $this->lastInsert = ['table' => $table, 'data' => $data];
        $cols = implode(',', array_keys($data));
        $marks = implode(',', array_fill(0, count($data), '?'));
        $this->pdo->prepare("INSERT INTO {$table} ({$cols}) VALUES ({$marks})")->execute(array_values($data));
        $this->insert_id = (int) $this->pdo->lastInsertId();

        return 1;
    }

    public function update(string $table, array $data, array $where, ?array $format = null, ?array $whereFormat = null): int|false
    {
        $this->updates[] = ['table' => $table, 'data' => $data, 'where' => $where];
        $set = implode(',', array_map(static fn (string $c): string => "{$c} = ?", array_keys($data)));
        $cond = implode(' AND ', array_map(static fn (string $c): string => "{$c} = ?", array_keys($where)));
        $stmt = $this->pdo->prepare("UPDATE {$table} SET {$set} WHERE {$cond}");
        $stmt->execute([...array_values($data), ...array_values($where)]);

        return $stmt->rowCount();
    }

    /** Test helper: run raw SQL (seeding, tampering). */
    public function exec(string $sql): void
    {
        $this->pdo->exec($sql);
    }

    private function run(?string $query): \PDOStatement
    {
        $this->queries[] = (string) $query;

        return $this->pdo->query((string) $query);
    }
}
