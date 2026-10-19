<?php

declare(strict_types=1);

namespace Belayline\Plugin\Tests\Support;

use PHPUnit\Framework\TestCase;
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

        $this->assertCount(4, $GLOBALS['wpas_test_dbdelta_queries']);
        $this->assertStringContainsString('wp_belayline_audit_log', $GLOBALS['wpas_test_dbdelta_queries'][0]);
        $this->assertStringContainsString('wp_belayline_approvals', $GLOBALS['wpas_test_dbdelta_queries'][1]);
        $this->assertStringContainsString('wp_belayline_grants', $GLOBALS['wpas_test_dbdelta_queries'][2]);
        $this->assertStringContainsString('wp_belayline_counters', $GLOBALS['wpas_test_dbdelta_queries'][3]);
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

        $this->assertCount(4, $GLOBALS['wpas_test_dbdelta_queries']);
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

    // --- version 4: fingerprint columns; grants table name --------------------

    public function testApprovalsGainTheFingerprintColumns(): void
    {
        Schema::install(new wpdb());

        $approvals = $GLOBALS['wpas_test_dbdelta_queries'][1];
        $this->assertStringContainsString('fingerprint CHAR(64) NULL', $approvals);
        $this->assertStringContainsString('fingerprint_kind VARCHAR(10) NULL', $approvals);
    }

    public function testAFreshInstallCreatesTheGrantsTable(): void
    {
        Schema::install(new wpdb());

        $this->assertStringContainsString('wp_belayline_grants', $GLOBALS['wpas_test_dbdelta_queries'][2]);
        $this->assertArrayNotHasKey(Schema::GRANTS_RENAME_CONFLICT_OPTION, $GLOBALS['wpas_test_options']);
    }

    /** Regression: 0.4.5 flagged a false "old and new grants tables both exist" conflict on every reinstall. */
    public function testInstallingTwiceOnASiteThatAlreadyHasTheGrantsTableSetsNoConflictFlag(): void
    {
        // A live site: every SHOW TABLES LIKE probe finds its table.
        $db = new class () extends wpdb {
            // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- overrides wpdb::get_var().
            public function get_var(?string $query = null): mixed
            {
                parent::get_var($query);

                return preg_match("/SHOW TABLES LIKE '([^']+)'/", (string) $query, $m) === 1 ? $m[1] : null;
            }
        };

        Schema::install($db);
        Schema::install($db);

        $this->assertArrayNotHasKey(Schema::GRANTS_RENAME_CONFLICT_OPTION, $GLOBALS['wpas_test_options']);
        $this->assertSame([], array_values(array_filter(
            $db->queries,
            static fn (string $query): bool => str_starts_with($query, 'RENAME TABLE')
        )));
    }

    /**
     * A 0.4.5 site that already shows the false notice: 0.4.6 keeps schema 5, so
     * the flag is only cleared on the next activation; until then nothing renders it.
     */
    public function testAStaleConflictFlagLeftBy045IsNeverShownAndActivationClearsIt(): void
    {
        $GLOBALS['wpas_test_options'][Schema::GRANTS_RENAME_CONFLICT_OPTION] = true;

        $this->assertFalse(method_exists(Schema::class, 'renderGrantsRenameConflictNotice'));
        $main = (string) file_get_contents(dirname(__DIR__, 2) . '/belayline-agent-governance.php');
        $this->assertStringNotContainsString('GrantsRenameConflict', $main);

        Schema::install(new wpdb());

        $this->assertArrayNotHasKey(Schema::GRANTS_RENAME_CONFLICT_OPTION, $GLOBALS['wpas_test_options']);
    }

    /** Static guard: a "legacy" table name equal to a current one is the 0.4.5 bug. */
    public function testNoLegacyTableConstantEqualsACurrentTableName(): void
    {
        $constants = (new \ReflectionClass(Schema::class))->getConstants();
        $current = array_filter($constants, static fn ($v, $k) => str_ends_with($k, '_TABLE') && !str_starts_with($k, 'LEGACY_'), ARRAY_FILTER_USE_BOTH);
        $legacy = array_filter($constants, static fn ($v, $k) => str_starts_with($k, 'LEGACY_') && str_ends_with($k, '_TABLE'), ARRAY_FILTER_USE_BOTH);

        $this->assertNotSame([], $current);
        foreach ($legacy as $name => $value) {
            $this->assertNotContains($value, $current, "Schema::{$name} equals a current table name");
        }
    }
}
