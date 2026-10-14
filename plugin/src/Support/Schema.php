<?php

declare(strict_types=1);

namespace Belayline\Plugin\Support;

use Belayline\Plugin\Audit\WpdbAuditSink;
use wpdb;

/**
 * Single source of truth for the plugin's five custom tables
 * ({@see \Belayline\Plugin\Audit\WpdbAuditSink},
 * {@see \Belayline\Plugin\Audit\WpdbApprovalStore} and
 * {@see \Belayline\Plugin\Approval\WpdbGrantStore}) and their
 * versioned installation.
 *
 * {@see install()} runs on activation (and on {@see maybeUpgrade()} whenever the
 * stored version falls behind) via `dbDelta()`, which diffs the declared shape
 * against what actually exists and issues only the ALTERs needed — safe to
 * rerun on every upgrade. The lazy `CREATE TABLE IF NOT EXISTS` fallbacks in
 * the two host classes exist only for a site that writes before it is ever
 * (re-)activated (e.g. after a manual file update); they build their
 * statements from the SAME column definitions below so the two paths can
 * never drift apart.
 *
 * dbDelta parses `CREATE TABLE {name} (` with a regex that breaks if the
 * statement includes `IF NOT EXISTS` (it would capture "IF" as the table
 * name), so {@see install()} must NOT add that clause — only the lazy
 * fallbacks may.
 */
final class Schema
{
    /**
     * Bump whenever the column defintions below change, or a stored option
     * changes shape; {@see maybeUpgrade()} reinstalls (dbDelta-diffs) and
     * reruns the option migrations once the stored option falls behind this.
     *
     * 3: `belayline_shadow_packs` became pack name => expiry ({@see ShadowMode}).
     * 4: approvals gained `fingerprint`/`fingerprint_kind` (AS-6); the grants
     *    table was renamed `belayline_grants` => `belayline_grants`
     *    ({@see grantsTable()}, {@see renameLegacyGrantsTable()}). Still
     *    unreleased at the time `probe_args TEXT NULL` was added (security
     *    fix: the approve-time re-probe now re-runs against the probe's own
     *    captured {@see \Belayline\Plugin\Approval\StateProbe::targetArgs()}
     *    instead of parsing them back out of the free-text summary), so this
     *    is a v4 addition, not a new version bump.
     * 5: new `belayline_counters` table ({@see countersTable()}) backing the
     *    atomic rate/quota/tripwire counters ({@see AtomicCounterStore}),
     *    replacing their non-atomic transients.
     * 6: new `belayline_changes` table ({@see changesTable()}) holding the
     *    per-call change history (before/after snapshots) captured while a
     *    governed call runs ({@see \Belayline\Plugin\Changes\ChangeCapture}).
     */
    public const VERSION = '6';

    public const VERSION_OPTION = 'belayline_schema_version';

    /**
     * Legacy grants table name, pre-v4. Named here, in
     * {@see renameLegacyGrantsTable()}, and by the opted-in uninstall path
     * ({@see \Belayline\Plugin\Support\UninstallManifest}), which
     * must also drop it when a crashed v4 upgrade left it behind — every
     * other reference in the plugin goes through {@see grantsTable()}.
     */
    public const LEGACY_GRANTS_TABLE = 'belayline_grants';

    /**
     * Set (never autoloaded) when {@see renameLegacyGrantsTable()} finds BOTH
     * the legacy and the current grants table present (a crashed earlier
     * rename): the current table is used and the legacy one is left alone
     * rather than guessed at. {@see renderGrantsRenameConflictNotice()} shows
     * an admin notice until an operator resolves it by hand.
     */
    public const GRANTS_RENAME_CONFLICT_OPTION = 'belayline_grants_rename_conflict';

    /**
     * Table base names (without the `$wpdb` prefix), named here so the
     * opted-in uninstall path ({@see \Belayline\Plugin\Support\UninstallManifest})
     * can drop the same three tables this class installs without guessing
     * at their names a second time.
     */
    public const AUDIT_LOG_TABLE = 'belayline_audit_log';
    public const APPROVALS_TABLE = 'belayline_approvals';
    public const GRANTS_TABLE = 'belayline_grants';
    public const COUNTERS_TABLE = 'belayline_counters';
    public const CHANGES_TABLE = 'belayline_changes';

    public static function auditLogTable(wpdb $db): string
    {
        return $db->prefix . self::AUDIT_LOG_TABLE;
    }

    public static function approvalsTable(wpdb $db): string
    {
        return $db->prefix . self::APPROVALS_TABLE;
    }

    /**
     * Pre-approval grants (AS-12). A SEPARATE table on purpose: a grant is not
     * an approval (it binds to a correlation scope + subject, never to one
     * exact action) and it must survive a request, so it is neither a row in
     * the approvals table nor a transient.
     */
    public static function grantsTable(wpdb $db): string
    {
        return $db->prefix . self::GRANTS_TABLE;
    }

    /**
     * Rate/quota/tripwire counters ({@see AtomicCounterStore}). A table, not
     * transients, because an increment must be one atomic statement.
     */
    public static function countersTable(wpdb $db): string
    {
        return $db->prefix . self::COUNTERS_TABLE;
    }

    /**
     * Change history: one row per recorded change, joined to its audit row by
     * `event_id`. A SEPARATE table from the audit log on purpose: the audit
     * row's hash chain covers `changes_sha256`, while these snapshot bodies
     * stay outside the chain so retention and the privacy eraser can delete
     * them without breaking it.
     */
    public static function changesTable(wpdb $db): string
    {
        return $db->prefix . self::CHANGES_TABLE;
    }

    /** Column/key body (no surrounding `CREATE TABLE ... ( )`) for the audit log table. */
    public static function auditLogColumns(): string
    {
        return "id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                event_id VARCHAR(64) NOT NULL,
                ts VARCHAR(40) NOT NULL,
                correlation_id VARCHAR(64) NOT NULL,
                pack VARCHAR(100) NOT NULL,
                ability VARCHAR(191) NOT NULL,
                tier TINYINT NULL,
                decision VARCHAR(20) NOT NULL,
                result VARCHAR(20) NULL,
                wp_user BIGINT NULL,
                ip VARCHAR(45) NULL,
                record_json LONGTEXT NOT NULL,
                prev_hash CHAR(64) NOT NULL,
                entry_hash CHAR(64) NOT NULL,
                PRIMARY KEY  (id),
                KEY correlation_id (correlation_id),
                KEY ability (ability)";
    }

    /**
     * Column/key body (no surrounding `CREATE TABLE ... ( )`) for the
     * approvals table. `fingerprint`/`fingerprint_kind` (AS-6, §3.3) are
     * nullable: a row written before v4, or for a Verb with no declared
     * state probe, has `fingerprint_kind = NULL`, read as `none`; the other
     * values are `probe` and `grant`. No backfill.
     *
     * `probe_args` (security fix, still v4): canonical JSON of the probe's
     * {@see \Belayline\Plugin\Approval\StateProbe::targetArgs()},
     * captured only alongside a `probe`-kind fingerprint. NULL for every
     * other kind and for rows written before this column existed; a
     * `probe`-kind row with a NULL/undecodable value is treated as stale by
     * the approve-time re-probe rather than skipped (fail closed).
     */
    public static function approvalsColumns(): string
    {
        return "id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                approval_id VARCHAR(64) NOT NULL,
                verb VARCHAR(191) NOT NULL,
                args_hash CHAR(64) NOT NULL,
                summary TEXT NULL,
                correlation_id VARCHAR(64) NOT NULL,
                audit_event_id VARCHAR(64) NULL,
                key_id VARCHAR(64) NULL,
                status VARCHAR(20) NOT NULL,
                token_hash CHAR(64) NULL,
                approver BIGINT NULL,
                reserved_req VARCHAR(64) NULL,
                reserved_ts DATETIME NULL,
                grant_id VARCHAR(64) NULL,
                fingerprint CHAR(64) NULL,
                fingerprint_kind VARCHAR(10) NULL,
                probe_args TEXT NULL,
                created_ts DATETIME NOT NULL,
                pending_expires_ts DATETIME NULL,
                expires_ts DATETIME NULL,
                consumed_ts DATETIME NULL,
                PRIMARY KEY  (id),
                UNIQUE KEY approval_id (approval_id),
                KEY status (status),
                KEY verb_args (verb, args_hash),
                KEY ref (key_id, verb, args_hash),
                KEY token_hash (token_hash)";
    }

    /**
     * Column/key body (no surrounding `CREATE TABLE ... ( )`) for the grants
     * table. The index mirrors the ONLY lookup the gate performs
     * ({@see \Belayline\Plugin\Approval\WpdbGrantStore::reserve()}):
     * (correlation_id, verb, status). `subject` is deliberately NOT in the key
     * — the candidate set per (correlation, verb) is tiny, and the subject
     * comparison stays an exact string match in the WHERE clause where the
     * "empty subject never matches" rule is enforced.
     */
    public static function grantsColumns(): string
    {
        return "id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                grant_id VARCHAR(64) NOT NULL,
                correlation_id VARCHAR(64) NOT NULL,
                verb VARCHAR(191) NOT NULL,
                remaining_count INT NOT NULL DEFAULT 0,
                subject VARCHAR(64) NULL,
                granted_by BIGINT NULL,
                plan_step_id VARCHAR(64) NULL,
                status VARCHAR(20) NOT NULL,
                created_ts DATETIME NOT NULL,
                expires_ts DATETIME NOT NULL,
                revoked_ts DATETIME NULL,
                PRIMARY KEY  (id),
                UNIQUE KEY grant_id (grant_id),
                KEY scope (correlation_id, verb, status)";
    }

    /**
     * Column/key body (no surrounding `CREATE TABLE ... ( )`) for the counters
     * table. `value` is a DOUBLE so one column holds both call counts and
     * summed spend; `expires_at` is a unix timestamp, indexed for the sweep.
     */
    public static function countersColumns(): string
    {
        return "counter_key VARCHAR(64) NOT NULL,
                value DOUBLE NOT NULL DEFAULT 0,
                expires_at BIGINT UNSIGNED NOT NULL,
                PRIMARY KEY  (counter_key),
                KEY expires_at (expires_at)";
    }

    /**
     * Column/key body (no surrounding `CREATE TABLE ... ( )`) for the change
     * history table. `restorable` is a bool plus a reason code
     * (`restorable_reason`: NULL when restorable, else why not — `truncated`,
     * `secret`, `redacted`, `irreversible`, `unencodable`). `ts` is UTC and
     * drives the retention purge. `restored_by_event` is stamped by the undo
     * engine (a later stage); NULL until then. `(event_id, seq)` is unique so
     * a change is never recorded twice for one event.
     */
    public static function changesColumns(): string
    {
        return "id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                event_id VARCHAR(64) NOT NULL,
                correlation_id VARCHAR(64) NOT NULL,
                seq INT UNSIGNED NOT NULL,
                object_type VARCHAR(40) NOT NULL,
                object_id VARCHAR(191) NOT NULL,
                object_subtype VARCHAR(191) NULL,
                action VARCHAR(20) NOT NULL,
                before_json LONGTEXT NULL,
                after_json LONGTEXT NULL,
                changed_fields TEXT NULL,
                restorable TINYINT(1) NOT NULL DEFAULT 0,
                restorable_reason VARCHAR(40) NULL,
                restored_by_event VARCHAR(64) NULL,
                ts DATETIME NOT NULL,
                PRIMARY KEY  (id),
                UNIQUE KEY event_seq (event_id, seq),
                KEY correlation_id (correlation_id),
                KEY ts (ts)";
    }

    /**
     * Create/upgrade the tables via `dbDelta()` and record the version that
     * was just installed. Safe to call repeatedly (dbDelta only issues the
     * ALTERs a diff actually needs).
     */
    public static function install(wpdb $db): void
    {
        self::ensureDbDelta();

        // MUST run before dbDelta(): dbDelta only ever creates or ALTERs the
        // table it's told about (belayline_grants); it has no idea a
        // differently-named table already holds the data.
        self::renameLegacyGrantsTable($db);

        $charset = $db->get_charset_collate();
        dbDelta([
            'CREATE TABLE ' . self::auditLogTable($db) . " (\n" . self::auditLogColumns() . "\n) {$charset};",
            'CREATE TABLE ' . self::approvalsTable($db) . " (\n" . self::approvalsColumns() . "\n) {$charset};",
            'CREATE TABLE ' . self::grantsTable($db) . " (\n" . self::grantsColumns() . "\n) {$charset};",
            'CREATE TABLE ' . self::countersTable($db) . " (\n" . self::countersColumns() . "\n) {$charset};",
            'CREATE TABLE ' . self::changesTable($db) . " (\n" . self::changesColumns() . "\n) {$charset};",
        ]);

        // Option migrations ride the same version gate as the tables. Each is
        // a no-op once its shape is current, so rerunning on activation is
        // safe.
        (new ShadowMode())->migrateLegacy();

        // v6: the retention purge cron. Scheduled here (idempotent) rather
        // than only on activation, because an upgrade from 0.4.x never
        // re-activates the plugin.
        ChangePurge::activate();

        // §3.4 item 3: first site bind, idempotent and shape-gated (already
        // bound is a no-op) rather than version-gated, exactly like the
        // migration above — covers both a fresh activation and an existing
        // install's first request after this schema upgrade, since this
        // method runs from both activation and {@see maybeUpgrade()}.
        self::firstSiteBind($db);

        update_option(self::VERSION_OPTION, self::VERSION, false);
    }

    /** @see EnvironmentGuard::firstBindIfNeeded() — duplicated here (no AuditSink/AdminChangeRecorder to inject into Schema::install()) rather than instantiating a full EnvironmentGuard. */
    private static function firstSiteBind(wpdb $db): void
    {
        if (get_option(EnvironmentGuard::OPTION, null) !== null) {
            return;
        }

        $host = SiteBinding::normalize(function_exists('home_url') ? (string) home_url() : '');
        update_option(EnvironmentGuard::OPTION, $host, false);
        (new AdminChangeRecorder(new WpdbAuditSink($db)))->environmentBound($host);
    }

    /**
     * Pre-v4 installs named the grants table `{prefix}belayline_grants`;
     * v4 renamed it to `{prefix}belayline_grants` ({@see grantsTable()}) so only
     * `Schema.php` ever names it, matching every other table (§3.6 item 4).
     * Runs unconditionally (idempotent, shape-checked): a fresh install has
     * no legacy table and no-ops immediately.
     */
    private static function renameLegacyGrantsTable(wpdb $db): void
    {
        $legacy = $db->prefix . self::LEGACY_GRANTS_TABLE;
        $current = self::grantsTable($db);

        if (!self::tableExists($db, $legacy)) {
            // Nothing to migrate. Also resolves a previously flagged conflict
            // (below): once the legacy table is gone (an admin dropped it by
            // hand), the notice has nothing left to warn about.
            delete_option(self::GRANTS_RENAME_CONFLICT_OPTION);

            return;
        }

        if (self::tableExists($db, $current)) {
            // Both tables present: a crashed earlier rename. Leave both, use
            // the new one (dbDelta creates/maintains it right after this
            // call returns) and flag it for a human rather than guess which
            // table is authoritative.
            update_option(self::GRANTS_RENAME_CONFLICT_OPTION, true, false);

            return;
        }

        $db->query('RENAME TABLE ' . $legacy . ' TO ' . $current);
    }

    private static function tableExists(wpdb $db, string $table): bool
    {
        return $db->get_var($db->prepare('SHOW TABLES LIKE %s', $table)) === $table;
    }

    /**
     * Admin notice for the {@see GRANTS_RENAME_CONFLICT_OPTION} flag. Wired
     * unconditionally (see `belayline-agent-governance.php`); a no-op render when the flag
     * isn't set, matching the `CapabilityPacksPage::pausedNotice()` pattern.
     */
    public static function renderGrantsRenameConflictNotice(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        if (!get_option(self::GRANTS_RENAME_CONFLICT_OPTION, false)) {
            return;
        }

        printf(
            '<div class="notice notice-warning"><p>%s</p></div>',
            esc_html__(
                'Belayline Agent Governance found both the old (belayline_grants) and new (belayline_grants) grants tables during an upgrade, which means an earlier upgrade did not finish. It is using the new table. Once you have confirmed no grants were lost, an administrator can drop the old table to clear this notice.',
                'belayline-agent-governance'
            )
        );
    }

    /** Cheap version check; reinstalls only when the stored option is behind {@see VERSION}. */
    public static function maybeUpgrade(wpdb $db): void
    {
        if (get_option(self::VERSION_OPTION, '') === self::VERSION) {
            return;
        }

        self::install($db);
    }

    private static function ensureDbDelta(): void
    {
        if (function_exists('dbDelta')) {
            return;
        }

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    }
}
