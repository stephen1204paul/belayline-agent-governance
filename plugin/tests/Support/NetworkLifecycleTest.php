<?php

declare(strict_types=1);

namespace Belayline\Plugin\Tests\Support;

use PHPUnit\Framework\TestCase;
use Belayline\Plugin\Audit\WpdbAuditSink;
use Belayline\Plugin\Identity\IdentityChain;
use Belayline\Plugin\Support\ApprovalSweep;
use Belayline\Plugin\Support\AuditRetention;
use Belayline\Plugin\Support\ChangePurge;
use Belayline\Plugin\Support\NetworkLifecycle;
use Belayline\Plugin\Support\RequestContext;
use Belayline\Plugin\Support\Schema;
use Belayline\Plugin\Support\UninstallManifest;
use Belayline\Plugin\Tests\Fakes\FakeIdentityProvider;
use Belayline\Plugin\Tests\Fakes\NetworkFake;
use wpdb;

final class NetworkLifecycleTest extends TestCase
{
    private const FILE = '/plugins/belayline-agent-governance/belayline-agent-governance.php';
    private const BASENAME = 'belayline-agent-governance/belayline-agent-governance.php';

    private const ALL_CRONS = [ApprovalSweep::HOOK, ChangePurge::HOOK, AuditRetention::HOOK];

    protected function setUp(): void
    {
        $GLOBALS['wpas_test_added_actions'] = [];
        $GLOBALS['wpas_test_dbdelta_queries'] = [];
        $GLOBALS['wpas_test_time'] = 1_800_000_000;
    }

    protected function tearDown(): void
    {
        NetworkFake::reset();
        RequestContext::reset();
        $GLOBALS['wpas_test_time'] = \time();
    }

    private function networkActivate(): void
    {
        $GLOBALS['wpas_test_net']['site_options']['active_sitewide_plugins'] = [self::BASENAME => 1_800_000_000];
    }

    /** @param list<int> $ids */
    private function sites(array $ids, int $current = 1): wpdb
    {
        return NetworkFake::boot($ids, $current);
    }

    public function testSingleSiteRegistersNothingAndRunsOnce(): void
    {
        $GLOBALS['wpas_test_multisite'] = false;
        NetworkLifecycle::register(new wpdb(), self::FILE);

        $this->assertSame([], $GLOBALS['wpas_test_added_actions']);

        $calls = [];
        $this->assertSame(1, NetworkLifecycle::forEachSite(static function (int $id) use (&$calls): void {
            $calls[] = $id;
        }));
        $this->assertCount(1, $calls);
    }

    public function testNetworkActivationInstallsTheCurrentSiteAndAnotherSiteInstallsLazilyOnFirstCronInit(): void
    {
        $db = $this->sites([1, 2]);
        $this->networkActivate();
        NetworkLifecycle::register($db, self::FILE);

        // Activation runs on site 1 only.
        NetworkLifecycle::installSite($db);
        $this->assertSame(Schema::VERSION, NetworkFake::state(1)['options'][Schema::VERSION_OPTION]);
        foreach (self::ALL_CRONS as $hook) {
            $this->assertArrayHasKey($hook, NetworkFake::state(1)['cron']);
        }
        $this->assertSame([], NetworkFake::state(2)['options'], 'site 2 is untouched at activation');

        // Site 2's first cron request.
        NetworkFake::switchTo(2);
        $GLOBALS['wpas_test_net']['doing_cron'] = true;
        $GLOBALS['wpas_test_dbdelta_queries'] = [];
        NetworkFake::fire('init');

        $this->assertSame(Schema::VERSION, $GLOBALS['wpas_test_options'][Schema::VERSION_OPTION]);
        $this->assertStringContainsString('wp_2_belayline_audit_log', implode("\n", $GLOBALS['wpas_test_dbdelta_queries']));
        foreach (self::ALL_CRONS as $hook) {
            $this->assertArrayHasKey($hook, $GLOBALS['wpas_test_cron'], "$hook scheduled on the lazily installed site");
        }
    }

    public function testFrontEndInitSchedulesMissingCronsButDoesNotInstall(): void
    {
        $db = $this->sites([1, 2], 2);
        $this->networkActivate();
        NetworkLifecycle::register($db, self::FILE);

        NetworkFake::fire('init');

        $this->assertArrayNotHasKey(Schema::VERSION_OPTION, $GLOBALS['wpas_test_options']);
        $this->assertSame([], $GLOBALS['wpas_test_dbdelta_queries']);
        foreach (self::ALL_CRONS as $hook) {
            $this->assertArrayHasKey($hook, $GLOBALS['wpas_test_cron']);
        }
    }

    public function testAnAdminInitOnAnUpToDateSiteDoesNotReinstall(): void
    {
        $db = $this->sites([1]);
        NetworkLifecycle::register($db, self::FILE);
        $GLOBALS['wpas_test_options'][Schema::VERSION_OPTION] = Schema::VERSION;
        $GLOBALS['wpas_test_net']['is_admin'] = true;

        NetworkFake::fire('init');

        $this->assertSame([], $GLOBALS['wpas_test_dbdelta_queries']);
    }

    public function testNewSiteInstallsWhenNetworkActiveAndTheCurrentBlogIsRestored(): void
    {
        $db = $this->sites([1, 5]);
        $this->networkActivate();
        NetworkLifecycle::register($db, self::FILE);

        NetworkFake::fire('wp_initialize_site', (object) ['blog_id' => '5']);

        $this->assertSame(1, get_current_blog_id());
        $this->assertSame('wp_', $db->prefix);
        $this->assertSame(Schema::VERSION, NetworkFake::state(5)['options'][Schema::VERSION_OPTION]);
        $this->assertStringContainsString('wp_5_belayline_approvals', implode("\n", $GLOBALS['wpas_test_dbdelta_queries']));
        foreach (self::ALL_CRONS as $hook) {
            $this->assertArrayHasKey($hook, NetworkFake::state(5)['cron']);
        }
        $this->assertSame([], NetworkFake::state(1)['options'], 'the creating site is untouched');
    }

    public function testNewSiteIsLeftAloneWhenThePluginIsNotNetworkActive(): void
    {
        $db = $this->sites([1, 5]);
        NetworkLifecycle::register($db, self::FILE);

        NetworkFake::fire('wp_initialize_site', (object) ['blog_id' => '5']);

        $this->assertSame([], NetworkFake::state(5)['options']);
        $this->assertSame([], $GLOBALS['wpas_test_dbdelta_queries']);
    }

    public function testDeletingASiteWhereThePluginIsActiveDropsItsTablesAndOptions(): void
    {
        $db = $this->sites([1, 7]);
        $this->networkActivate();
        NetworkFake::seed(7, [Schema::VERSION_OPTION => '7', 'blogname' => 'doomed'], [ApprovalSweep::HOOK => 1]);
        NetworkLifecycle::register($db, self::FILE);

        NetworkFake::fire('wp_uninitialize_site', (object) ['blog_id' => '7']);

        foreach (UninstallManifest::TABLE_BASENAMES as $base) {
            $this->assertContains("DROP TABLE IF EXISTS `wp_7_{$base}`", $db->queries);
        }
        $this->assertArrayNotHasKey(Schema::VERSION_OPTION, NetworkFake::state(7)['options']);
        $this->assertSame([], NetworkFake::state(7)['cron']);
        $this->assertSame(1, get_current_blog_id());
    }

    public function testDeletingASiteWhereThePluginIsLocallyActiveAlsoCleansUp(): void
    {
        $db = $this->sites([1, 7]);
        NetworkFake::seed(7, ['active_plugins' => [self::BASENAME]]);
        NetworkLifecycle::register($db, self::FILE);

        NetworkFake::fire('wp_uninitialize_site', (object) ['blog_id' => '7']);

        $this->assertContains('DROP TABLE IF EXISTS `wp_7_belayline_audit_log`', $db->queries);
    }

    public function testDeletingASiteWhereThePluginIsInactiveDoesNothing(): void
    {
        $db = $this->sites([1, 7]);
        NetworkFake::seed(7, ['active_plugins' => ['other/other.php']]);
        NetworkLifecycle::register($db, self::FILE);

        NetworkFake::fire('wp_uninitialize_site', (object) ['blog_id' => '7']);

        $this->assertSame([], $db->queries);
    }

    public function testNetworkDeactivationUnschedulesEverySiteInBatchesOfOneHundred(): void
    {
        $ids = range(1, 250);
        $this->sites($ids);
        foreach ($ids as $id) {
            NetworkFake::seed($id, [], array_fill_keys(self::ALL_CRONS, 1));
        }

        $visited = NetworkLifecycle::deactivateNetwork();

        $this->assertSame(250, $visited);
        $this->assertSame([100, 100, 100], array_column($GLOBALS['wpas_test_net']['get_sites_calls'], 'number'));
        $this->assertSame([0, 100, 200], array_column($GLOBALS['wpas_test_net']['get_sites_calls'], 'offset'));
        foreach ([1, 2, 100, 101, 250] as $id) {
            $this->assertSame([], NetworkFake::state($id)['cron'], "site $id crons cleared");
        }
        $this->assertSame(1, get_current_blog_id());
    }

    public function testTheSiteWalkStopsAtTheCap(): void
    {
        $this->sites(range(1, 30));

        $visited = NetworkLifecycle::forEachSite(static function (): void {
        }, 10, 25);

        $this->assertSame(25, $visited);
        $this->assertSame([10, 10, 5], array_column($GLOBALS['wpas_test_net']['get_sites_calls'], 'number'));
    }

    public function testRestoreHappensEvenWhenThePerSiteCallbackThrows(): void
    {
        $this->sites([1, 2]);

        try {
            NetworkLifecycle::forEachSite(static function (): void {
                throw new \RuntimeException('boom');
            });
            $this->fail('expected the exception to propagate');
        } catch (\RuntimeException) {
            $this->assertSame(1, get_current_blog_id());
        }
    }

    public function testAppendLockNamesDifferPerSiteAndFitMysqlsLimit(): void
    {
        $db = $this->sites([1, 2, 3]);
        $sink = new WpdbAuditSink($db);
        $db->varReturn = 1;

        $names = [];
        foreach ([1, 2, 3] as $id) {
            $db->prefix = NetworkFake::prefixFor($id);
            $db->queries = [];
            $this->assertTrue($sink->tryAppendLock());
            $sink->releaseAppendLock();
            $this->assertCount(2, $db->queries);
            preg_match("/GET_LOCK\\('([^']+)'/", $db->queries[0], $m);
            preg_match("/RELEASE_LOCK\\('([^']+)'/", $db->queries[1], $r);
            $this->assertSame($m[1], $r[1], 'release uses the same name as acquire');
            $this->assertLessThanOrEqual(64, strlen($m[1]));
            $names[$id] = $m[1];
        }

        $this->assertCount(3, array_unique($names));
    }

    public function testMemoizedPrincipalIsForgottenWhenTheBlogSwitches(): void
    {
        $db = $this->sites([1, 2]);
        NetworkLifecycle::register($db, self::FILE);

        RequestContext::configure(new IdentityChain([new FakeIdentityProvider(['user:5', 'role:editor'])]));
        $this->assertSame(['user:5', 'role:editor'], RequestContext::currentTokens());
        $this->assertSame('user:5', RequestContext::tokenId());

        // Same chain object cannot change, so swap the configured chain
        // WITHOUT configure() (which would reset by itself): the memo must
        // only be dropped by the switch_blog hook.
        $ref = new \ReflectionProperty(RequestContext::class, 'identity');
        $ref->setValue(null, new IdentityChain([new FakeIdentityProvider(['user:5', 'role:shop_manager'])]));
        $this->assertSame(['user:5', 'role:editor'], RequestContext::currentTokens(), 'memo holds without a switch');

        NetworkFake::switchTo(2);

        $this->assertSame(['user:5', 'role:shop_manager'], RequestContext::currentTokens());
        $this->assertSame('user:5', RequestContext::tokenId());
    }
}
