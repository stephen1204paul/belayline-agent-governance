<?php

declare(strict_types=1);

namespace Belayline\Plugin\Tests\Support;

use PHPUnit\Framework\TestCase;
use Belayline\Plugin\Support\AuditRetention;
use Belayline\Plugin\Support\ChangePurge;
use Belayline\Plugin\Support\EnvironmentGuard;
use Belayline\Plugin\Support\Schema;
use Belayline\Plugin\Support\ShadowMode;
use wpdb;

/**
 * Exercises the version bookkeeping around dbDelta() install/upgrade —
 * dbDelta itself is shimmed (see tests/bootstrap.php) to just record the
 * statements it was handed, since diffing against a real database is out of
 * scope for this suite.
 */
final class SchemaTest extends TestCase
{
    private const NOW = 1_800_000_000;

    protected function setUp(): void
    {
        $GLOBALS['wpas_test_options'] = [];
        $GLOBALS['wpas_test_dbdelta_queries'] = [];
        $GLOBALS['wpas_test_time'] = self::NOW;
    }

    protected function tearDown(): void
    {
        $GLOBALS['wpas_test_options'] = [];
        $GLOBALS['wpas_test_dbdelta_queries'] = [];
        $GLOBALS['wpas_test_time'] = \time();
        $GLOBALS['wpas_test_user_caps'] = [];
        unset($GLOBALS['wpas_test_home_url']);
    }

    // --- AS-7 §3.4 item 3: first site bind ------------------------------------

    public function testInstallBindsTheSiteOnFirstActivation(): void
    {
        $GLOBALS['wpas_test_home_url'] = 'https://example.com';
        $db = new wpdb();
        $db->varReturnQueue = [null]; // legacy grants table does not exist

        Schema::install($db);

        $this->assertSame('example.com', $GLOBALS['wpas_test_options'][EnvironmentGuard::OPTION]);
    }

    public function testInstallDoesNotRebindAnAlreadyBoundSite(): void
    {
        $GLOBALS['wpas_test_options'][EnvironmentGuard::OPTION] = 'already-bound.example';
        $GLOBALS['wpas_test_home_url'] = 'https://different.example';
        $db = new wpdb();
        $db->varReturnQueue = [null];

        Schema::install($db);

        $this->assertSame('already-bound.example', $GLOBALS['wpas_test_options'][EnvironmentGuard::OPTION]);
    }

    public function testInstallStoresTheCurrentSchemaVersion(): void
    {
        Schema::install(new wpdb());

        $this->assertSame(Schema::VERSION, $GLOBALS['wpas_test_options'][Schema::VERSION_OPTION]);
    }

    public function testInstallHandsEveryTableStatementToDbDelta(): void
    {
        Schema::install(new wpdb());

        $this->assertCount(6, $GLOBALS['wpas_test_dbdelta_queries']);
        $this->assertStringContainsString('wp_belayline_audit_log', $GLOBALS['wpas_test_dbdelta_queries'][0]);
        $this->assertStringContainsString('wp_belayline_approvals', $GLOBALS['wpas_test_dbdelta_queries'][1]);
        $this->assertStringContainsString('wp_belayline_grants', $GLOBALS['wpas_test_dbdelta_queries'][2]);
        $this->assertStringContainsString('wp_belayline_counters', $GLOBALS['wpas_test_dbdelta_queries'][3]);
        $this->assertStringContainsString('wp_belayline_changes', $GLOBALS['wpas_test_dbdelta_queries'][4]);
        $this->assertStringContainsString('wp_belayline_audit_checkpoint', $GLOBALS['wpas_test_dbdelta_queries'][5]);
    }

    // --- version 6: change history table ----------------------------------------

    public function testTheChangesTableCarriesEveryDocumentedColumnAndIsUniquePerEventSeq(): void
    {
        Schema::install(new wpdb());

        $changes = $GLOBALS['wpas_test_dbdelta_queries'][4];
        $fragments = [
            'event_id VARCHAR(64) NOT NULL',
            'correlation_id VARCHAR(64) NOT NULL',
            'seq INT UNSIGNED NOT NULL',
            'object_type VARCHAR(40) NOT NULL',
            'object_id VARCHAR(191) NOT NULL',
            'object_subtype VARCHAR(191) NULL',
            'action VARCHAR(20) NOT NULL',
            'before_json LONGTEXT NULL',
            'after_json LONGTEXT NULL',
            'changed_fields TEXT NULL',
            'restorable TINYINT(1) NOT NULL DEFAULT 0',
            'restorable_reason VARCHAR(40) NULL',
            'restored_by_event VARCHAR(64) NULL',
            'ts DATETIME NOT NULL',
            'UNIQUE KEY event_seq (event_id, seq)',
        ];
        foreach ($fragments as $fragment) {
            $this->assertStringContainsString($fragment, $changes);
        }
    }

    public function testUpgradingFromVersionSixCreatesTheCheckpointTableAndSchedulesTheAuditRetentionCron(): void
    {
        $GLOBALS['wpas_test_options'][Schema::VERSION_OPTION] = '6';
        $GLOBALS['wpas_test_cron'] = [];

        Schema::maybeUpgrade(new wpdb());

        $this->assertSame('7', $GLOBALS['wpas_test_options'][Schema::VERSION_OPTION]);
        $this->assertStringContainsString('wp_belayline_audit_checkpoint', implode("\n", $GLOBALS['wpas_test_dbdelta_queries']));
        $this->assertArrayHasKey(AuditRetention::HOOK, $GLOBALS['wpas_test_cron']);
        $this->assertArrayNotHasKey(AuditRetention::OPTION, $GLOBALS['wpas_test_options'], 'upgrading never turns retention on');
    }

    public function testTheCheckpointTableCarriesTheDocumentedColumns(): void
    {
        Schema::install(new wpdb());

        $sql = $GLOBALS['wpas_test_dbdelta_queries'][5];
        foreach (['created_ts DATETIME NOT NULL', 'pruned_through_id BIGINT UNSIGNED NOT NULL', 'pruned_through_entry_hash CHAR(64) NOT NULL', 'pruned_count BIGINT UNSIGNED NOT NULL'] as $fragment) {
            $this->assertStringContainsString($fragment, $sql);
        }
    }

    public function testUpgradingFromVersionFiveCreatesTheChangesTableAndSchedulesThePurge(): void
    {
        $GLOBALS['wpas_test_options'][Schema::VERSION_OPTION] = '5';
        $GLOBALS['wpas_test_cron'] = [];

        Schema::maybeUpgrade(new wpdb());

        $this->assertSame('7', Schema::VERSION);
        $this->assertSame('7', $GLOBALS['wpas_test_options'][Schema::VERSION_OPTION]);
        $this->assertStringContainsString('wp_belayline_changes', implode("\n", $GLOBALS['wpas_test_dbdelta_queries']));
        $this->assertArrayHasKey(ChangePurge::HOOK, $GLOBALS['wpas_test_cron'], 'an upgrade never re-activates, so install() must schedule the purge');
    }

    public function testTheCountersTableKeysByCounterKeyAndIndexesExpiry(): void
    {
        Schema::install(new wpdb());

        $counters = $GLOBALS['wpas_test_dbdelta_queries'][3];
        $this->assertStringContainsString('counter_key VARCHAR(64) NOT NULL', $counters);
        $this->assertStringContainsString('value DOUBLE NOT NULL DEFAULT 0', $counters);
        $this->assertStringContainsString('PRIMARY KEY  (counter_key)', $counters);
        $this->assertStringContainsString('KEY expires_at (expires_at)', $counters);
    }

    public function testTheGrantsTableIndexesTheOnlyLookupTheGatePerforms(): void
    {
        Schema::install(new wpdb());

        $grants = $GLOBALS['wpas_test_dbdelta_queries'][2];
        $this->assertStringContainsString('KEY scope (correlation_id, verb, status)', $grants);
    }

    public function testInstallStatementsNeverIncludeIfNotExists(): void
    {
        // dbDelta parses `CREATE TABLE {name}` with a regex that breaks if the
        // statement carries an `IF NOT EXISTS` clause (it would capture "IF" as
        // the table name) — that clause belongs ONLY to the lazy fallbacks.
        Schema::install(new wpdb());

        foreach ($GLOBALS['wpas_test_dbdelta_queries'] as $query) {
            $this->assertStringNotContainsStringIgnoringCase('IF NOT EXISTS', $query);
        }
    }

    public function testMaybeUpgradeNoOpsWhenTheStoredVersionIsCurrent(): void
    {
        $GLOBALS['wpas_test_options'][Schema::VERSION_OPTION] = Schema::VERSION;

        Schema::maybeUpgrade(new wpdb());

        $this->assertSame([], $GLOBALS['wpas_test_dbdelta_queries']);
    }

    public function testMaybeUpgradeReinstallsWhenTheStoredVersionIsBehind(): void
    {
        $GLOBALS['wpas_test_options'][Schema::VERSION_OPTION] = '0';

        Schema::maybeUpgrade(new wpdb());

        $this->assertCount(6, $GLOBALS['wpas_test_dbdelta_queries']);
        $this->assertSame(Schema::VERSION, $GLOBALS['wpas_test_options'][Schema::VERSION_OPTION]);
    }

    public function testMaybeUpgradeReinstallsWhenNoVersionWasEverStored(): void
    {
        Schema::maybeUpgrade(new wpdb());

        $this->assertSame(Schema::VERSION, $GLOBALS['wpas_test_options'][Schema::VERSION_OPTION]);
    }

    // --- version 3: the shadow option grew an expiry ---------------------------

    public function testUpgradingFromVersionTwoGivesALegacyShadowListAFullWindow(): void
    {
        // A site mid-observation must not be switched to enforcement by the
        // upgrade: each listed pack gets MAX_TTL from the moment of upgrade.
        $GLOBALS['wpas_test_options'][Schema::VERSION_OPTION] = '2';
        $GLOBALS['wpas_test_options'][ShadowMode::OPTION] = ['support-agent', 'owner'];

        Schema::maybeUpgrade(new wpdb());

        $this->assertSame(Schema::VERSION, $GLOBALS['wpas_test_options'][Schema::VERSION_OPTION]);
        $this->assertSame([
            'support-agent' => self::NOW + ShadowMode::MAX_TTL,
            'owner' => self::NOW + ShadowMode::MAX_TTL,
        ], $GLOBALS['wpas_test_options'][ShadowMode::OPTION]);
        $this->assertSame(['support-agent', 'owner'], (new ShadowMode())->packs());
    }

    public function testActivationOnAnAlreadyMigratedOptionChangesNothing(): void
    {
        $GLOBALS['wpas_test_options'][ShadowMode::OPTION] = ['support-agent' => self::NOW + 60];

        Schema::install(new wpdb());

        $this->assertSame(['support-agent' => self::NOW + 60], $GLOBALS['wpas_test_options'][ShadowMode::OPTION]);
    }

    public function testACurrentVersionNeverTouchesTheShadowOption(): void
    {
        $GLOBALS['wpas_test_options'][Schema::VERSION_OPTION] = Schema::VERSION;
        $GLOBALS['wpas_test_options'][ShadowMode::OPTION] = ['support-agent'];

        Schema::maybeUpgrade(new wpdb());

        // Left as-is: the reader fails closed on the legacy shape, the sweep
        // retires it. Only an upgrade hands out a fresh window.
        $this->assertSame(['support-agent'], $GLOBALS['wpas_test_options'][ShadowMode::OPTION]);
    }

    // --- version 4: fingerprint columns + grants table rename -------------

    public function testApprovalsGainTheFingerprintColumns(): void
    {
        Schema::install(new wpdb());

        $approvals = $GLOBALS['wpas_test_dbdelta_queries'][1];
        $this->assertStringContainsString('fingerprint CHAR(64) NULL', $approvals);
        $this->assertStringContainsString('fingerprint_kind VARCHAR(10) NULL', $approvals);
    }

    public function testAFreshInstallCreatesTheGrantsTableUnderItsNewNameDirectly(): void
    {
        $db = new wpdb();
        // No legacy table on a fresh install: both existence probes miss.
        $db->varReturnQueue = [null];

        Schema::install($db);

        $this->assertStringContainsString('wp_belayline_grants', $GLOBALS['wpas_test_dbdelta_queries'][2]);
        $this->assertSame([], self::renameQueries($db), 'a fresh install has nothing to RENAME');
        $this->assertFalse($GLOBALS['wpas_test_options'][Schema::GRANTS_RENAME_CONFLICT_OPTION] ?? false);
    }

    /** @return list<string> */
    private static function renameQueries(wpdb $db): array
    {
        return array_values(array_filter(
            $db->queries,
            static fn (string $query): bool => str_starts_with($query, 'RENAME TABLE')
        ));
    }

    public function testUpgradingFromVersionThreeRenamesTheLegacyGrantsTable(): void
    {
        $GLOBALS['wpas_test_options'][Schema::VERSION_OPTION] = '3';
        $db = new wpdb();
        // Legacy table found (SHOW TABLES LIKE echoes the name back); the new
        // name isn't there yet.
        $db->varReturnQueue = ['wp_belayline_grants', null];

        Schema::maybeUpgrade($db);

        $this->assertSame(
            ['RENAME TABLE wp_belayline_grants TO wp_belayline_grants'],
            self::renameQueries($db)
        );
        $this->assertSame(Schema::VERSION, $GLOBALS['wpas_test_options'][Schema::VERSION_OPTION]);
        $this->assertArrayNotHasKey(Schema::GRANTS_RENAME_CONFLICT_OPTION, $GLOBALS['wpas_test_options']);
    }

    public function testRunningTheUpgradeASecondTimeIsANoOp(): void
    {
        // First run: legacy present, renames it. Second run (Schema::install()
        // directly, bypassing the version short-circuit so the rename probe
        // itself is exercised again): the legacy table is gone, so there's
        // nothing left to RENAME and no error.
        $GLOBALS['wpas_test_options'][Schema::VERSION_OPTION] = '3';
        $db = new wpdb();
        $db->varReturnQueue = ['wp_belayline_grants', null];
        Schema::maybeUpgrade($db);

        $this->assertSame(Schema::VERSION, $GLOBALS['wpas_test_options'][Schema::VERSION_OPTION]);

        $db->varReturnQueue = [null];
        $db->queries = [];
        Schema::install($db);

        $this->assertSame([], self::renameQueries($db), 'nothing left to RENAME once already migrated');
        $this->assertSame(Schema::VERSION, $GLOBALS['wpas_test_options'][Schema::VERSION_OPTION]);
    }

    public function testBothGrantsTablesPresentLeavesBothAndFlagsForAnAdmin(): void
    {
        // A crashed earlier rename: both the legacy and the new table exist.
        $GLOBALS['wpas_test_options'][Schema::VERSION_OPTION] = '3';
        $db = new wpdb();
        $db->varReturnQueue = ['wp_belayline_grants', 'wp_belayline_grants'];

        Schema::maybeUpgrade($db);

        $this->assertSame([], self::renameQueries($db), 'neither table is touched when both exist');
        $this->assertTrue($GLOBALS['wpas_test_options'][Schema::GRANTS_RENAME_CONFLICT_OPTION]);
        // dbDelta still receives (and so maintains) the NEW table's name.
        $this->assertStringContainsString('wp_belayline_grants', $GLOBALS['wpas_test_dbdelta_queries'][2]);
    }

    public function testTheConflictFlagClearsOnceTheLegacyTableIsGone(): void
    {
        $GLOBALS['wpas_test_options'][Schema::GRANTS_RENAME_CONFLICT_OPTION] = true;
        $db = new wpdb();
        $db->varReturnQueue = [null];

        Schema::install($db);

        $this->assertArrayNotHasKey(Schema::GRANTS_RENAME_CONFLICT_OPTION, $GLOBALS['wpas_test_options']);
    }

    public function testTheConflictNoticeRendersOnlyWhenTheFlagIsSet(): void
    {
        $GLOBALS['wpas_test_user_caps']['manage_options'] = true;

        ob_start();
        Schema::renderGrantsRenameConflictNotice();
        $this->assertSame('', ob_get_clean());

        $GLOBALS['wpas_test_options'][Schema::GRANTS_RENAME_CONFLICT_OPTION] = true;

        ob_start();
        Schema::renderGrantsRenameConflictNotice();
        $notice = ob_get_clean();

        $this->assertStringContainsString('notice-warning', $notice);
        $this->assertStringContainsString('belayline_grants', $notice);
    }

    public function testTheConflictNoticeIsHiddenFromUsersWithoutManageOptions(): void
    {
        $GLOBALS['wpas_test_options'][Schema::GRANTS_RENAME_CONFLICT_OPTION] = true;
        $GLOBALS['wpas_test_user_caps']['manage_options'] = false;

        ob_start();
        Schema::renderGrantsRenameConflictNotice();

        $this->assertSame('', ob_get_clean());
    }
}
