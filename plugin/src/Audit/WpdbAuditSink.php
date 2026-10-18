<?php

declare(strict_types=1);

namespace Belayline\Plugin\Audit;

use Belayline\Audit\AuditRecord;
use Belayline\Audit\AuditSink;
use Belayline\Audit\HashChain;
use Belayline\Plugin\Support\Schema;
use wpdb;

/**
 * Append-only audit store backed by a custom WordPress table. Each row links into
 * the {@see HashChain}: prev_hash = the prior row's entry_hash (GENESIS for the
 * first), entry_hash = sha256(prev_hash + canonical_json). Tampering with or
 * deleting any row is then detectable by re-verifying the chain.
 *
 * The table is normally created/upgraded at activation by {@see Schema::install()}.
 * As a safety net for a site that writes before it was ever (re-)activated, the
 * table is ALSO created lazily on first write (CREATE TABLE IF NOT EXISTS, guarded
 * once per request), from the exact same column definitions in {@see Schema} so
 * the two paths can never disagree on shape.
 *
 * Concurrency: reading the previous hash and inserting must not interleave, or two
 * writers could read the same prev hash and fork the chain. The append is therefore
 * serialized with a MySQL advisory lock (GET_LOCK), which also covers the
 * empty-table head case that row locking alone would miss.
 */
final class WpdbAuditSink implements AuditSink
{
    /** Advisory-lock name prefix; see {@see lockName()} for the per-site suffix. */
    private const LOCK_PREFIX = 'belayline_audit_append_';

    private bool $ensured = false;

    public function __construct(private readonly wpdb $db)
    {
    }

    public function table(): string
    {
        return Schema::auditLogTable($this->db);
    }

    public function append(AuditRecord $record): void
    {
        $this->ensureTable();

        $table = $this->table();
        $json = $record->canonicalJson();
        $data = $record->toArray();

        $this->lock();
        try {
            $prev = $this->lastHash();
            $entryHash = HashChain::entryHash($prev, $json);

            $this->db->insert(
                $table,
                [
                    'event_id' => $record->id,
                    'ts' => $record->ts,
                    'correlation_id' => $record->correlationId,
                    'pack' => $record->pack,
                    'ability' => $record->ability,
                    'tier' => $record->tier,
                    'decision' => $record->decision->value,
                    'result' => $record->result,
                    'wp_user' => $data['actor']['wp_user'],
                    'ip' => $record->ip,
                    'record_json' => $json,
                    'prev_hash' => $prev,
                    'entry_hash' => $entryHash,
                ],
                ['%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%d', '%s', '%s', '%s', '%s'],
            );
        } finally {
            $this->unlock();
        }
    }

    /**
     * Take the append lock for a caller that must exclude appenders (the audit
     * pruner). Unlike the append path this reports failure, so a pruner that
     * cannot get the lock skips its run instead of racing.
     */
    public function tryAppendLock(): bool
    {
        return (int) $this->db->get_var($this->db->prepare('SELECT GET_LOCK(%s, %d)', $this->lockName(), 5)) === 1;
    }

    public function releaseAppendLock(): void
    {
        $this->unlock();
    }

    /**
     * GET_LOCK names are server-global, so on multisite the name must be
     * per-site or two sites' appends would serialize each other. The audit
     * table name carries the site's prefix (wp_2_...), so hash that: a fixed
     * 55 chars, well inside MySQL's 64-char limit however long the prefix is.
     */
    private function lockName(): string
    {
        return self::LOCK_PREFIX . md5($this->table());
    }

    /** Serialize appends so concurrent writers can't read the same prev hash. */
    private function lock(): void
    {
        $this->db->get_var($this->db->prepare('SELECT GET_LOCK(%s, %d)', $this->lockName(), 5));
    }

    private function unlock(): void
    {
        $this->db->get_var($this->db->prepare('SELECT RELEASE_LOCK(%s)', $this->lockName()));
    }

    /** The most recent entry's hash, or GENESIS if the log is empty. */
    private function lastHash(): string
    {
        $table = $this->table();
        // phpcs:ignore WordPress.DB.PreparedSQL -- table name is a trusted internal constant.
        $hash = $this->db->get_var("SELECT entry_hash FROM {$table} ORDER BY id DESC LIMIT 1");

        if (is_string($hash) && $hash !== '') {
            return $hash;
        }

        // Empty log. A fresh install starts at GENESIS, but a log whose every row was
        // pruned must continue from its checkpoint, or the next row would claim
        // GENESIS as its predecessor and fail verification.
        $anchor = $this->db->get_var(
            $this->db->prepare('SELECT pruned_through_entry_hash FROM %i ORDER BY id DESC LIMIT 1', Schema::checkpointTable($this->db))
        );

        return is_string($anchor) && $anchor !== '' ? $anchor : HashChain::GENESIS;
    }

    private function ensureTable(): void
    {
        if ($this->ensured) {
            return;
        }

        $table = $this->table();
        $charset = $this->db->get_charset_collate();

        // dbDelta is finicky; a direct guarded CREATE (from the SAME column
        // definitions Schema::install() uses) is simpler and idempotent here.
        $this->db->query(
            "CREATE TABLE IF NOT EXISTS {$table} (\n" . Schema::auditLogColumns() . "\n) {$charset}"
        );

        $this->ensured = true;
    }
}
