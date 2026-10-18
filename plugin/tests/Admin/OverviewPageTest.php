<?php

declare(strict_types=1);

namespace Belayline\Plugin\Tests\Admin;

use Belayline\Audit\AuditDecision;
use Belayline\Audit\AuditRecord;
use Belayline\Plugin\Admin\Map\MapData;
use Belayline\Plugin\Admin\Map\WhoResolver;
use Belayline\Plugin\Admin\OverviewPage;
use Belayline\Plugin\Audit\AuditReader;
use Belayline\Plugin\Audit\ChainStatus;
use Belayline\Plugin\Audit\WpdbApprovalStore;
use Belayline\Plugin\Audit\WpdbAuditSink;
use Belayline\Plugin\Tests\Fakes\SqliteWpdb;
use PHPUnit\Framework\TestCase;

/** The Overview tab: stat cards, the map's markup contract, the waiting preview, and the cached chain check. */
final class OverviewPageTest extends TestCase
{
    private const NOW = 1_800_000_000;

    private SqliteWpdb $db;
    private WpdbAuditSink $sink;

    protected function setUp(): void
    {
        $GLOBALS['wpas_test_transients'] = [];
        $GLOBALS['wpas_test_time'] = self::NOW;
        $GLOBALS['wpas_test_user_caps'] = ['manage_options' => true];
        $this->db = new SqliteWpdb();
        $this->sink = new WpdbAuditSink($this->db);
    }

    protected function tearDown(): void
    {
        $GLOBALS['wpas_test_transients'] = [];
        $GLOBALS['wpas_test_time'] = time();
        $GLOBALS['wpas_test_user_caps'] = [];
    }

    private function add(string $decision, ?string $result, string $ability, string $token, string $pack = 'content', int $ago = 60): void
    {
        $ts = gmdate('Y-m-d\TH:i:s\Z', self::NOW - $ago);
        $actor = ['token_id' => $token, 'wp_user' => 1];
        $this->sink->append($result !== null
            ? AuditRecord::execution('e' . uniqid(), $ts, 'c', $pack, $actor, $ability, 0, [], $result)
            : AuditRecord::decision('e' . uniqid(), $ts, 'c', $pack, $actor, $ability, 1, [], AuditDecision::from($decision)));
    }

    private function page(?WpdbApprovalStore $approvals = null): OverviewPage
    {
        $reader = new AuditReader($this->db);
        $who = new WhoResolver(static fn (): array => ['app:u1' => 'Claude'], static fn (int $id): ?string => 'Stephen', static fn (array $ids): array => []);

        return new OverviewPage(new MapData($this->db, $who), new ChainStatus($reader), $reader, $approvals, $who, self::NOW);
    }

    private function main(OverviewPage $page): string
    {
        ob_start();
        $page->renderMain();

        return (string) ob_get_clean();
    }

    public function testStatCardsMapAndNodeContract(): void
    {
        $this->add('allowed', 'success', 'specflux-site-agent/update-page', 'app:u1');
        $this->add('allowed', 'success', 'specflux-site-agent/update-page', 'app:u1', 'content', 90000);
        $this->add('denied', null, 'core/get-user-info', 'user:1', 'site-readonly');

        $out = $this->main($this->page());

        $this->assertStringContainsString('Calls today', $out);
        $this->assertStringContainsString('Refused today', $out);
        $this->assertStringContainsString('Log intact', $out);
        $this->assertStringContainsString('Verified <span class="aui-mono">just now</span>', $out);
        $this->assertStringContainsString('Last 7 days, 3 calls.', $out);
        $this->assertStringContainsString('<svg class="aui-spark" viewBox="0 0 96 32"', $out);

        $this->assertStringContainsString('class="aui-map" id="belayline-map"', $out);
        $this->assertStringContainsString('data-period="Last 7 days"', $out);
        $this->assertMatchesRegularExpression('#data-paths="[^"]*w-app-claude,t-specflux-site-agent,p-content,o-ran,2#', $out);
        // Four lanes, every box a real button with its id and count; outcome boxes always all five.
        $this->assertSame(4, substr_count($out, 'class="aui-lane"'));
        $this->assertStringContainsString('<button type="button" class="aui-node" data-node="w-app-claude" data-count="2" data-filter-key="token" data-filter-value="app:u1">', $out);
        $this->assertSame(5, preg_match_all('/data-node="o-/', $out));
        $this->assertStringContainsString('data-activity-url="https://example.test/wp-admin/admin.php?page=belayline-activity&#038;section=calls"', str_replace('&amp;', '&#038;', $out));
        $this->assertStringContainsString('data-aui-views="belayline-map-table-view,belayline-map-view"', $out, 'the table twin switch');
    }

    public function testEmptyLogShowsTheDashedEmptyStateInsteadOfAMap(): void
    {
        $out = $this->main($this->page());

        $this->assertStringContainsString('aui-empty--dashed', $out);
        $this->assertStringContainsString('No agent calls yet', $out);
        $this->assertStringNotContainsString('class="aui-map"', $out);
        $this->assertStringContainsString('Nothing is waiting', $out);
    }

    public function testTheChainIsVerifiedOnceAndServedFromTheCacheNextTime(): void
    {
        $this->add('allowed', 'success', 'core/get-site-info', 'user:1');
        $page = $this->page();

        $this->main($page);
        $this->db->queries = [];
        $out = $this->main($page);

        $chainReads = array_filter($this->db->queries, static fn (string $q): bool => str_contains($q, 'prev_hash, record_json'));
        $this->assertSame([], $chainReads, 'the second load does not walk the chain');
        $this->assertArrayHasKey(ChainStatus::CACHE_KEY, $GLOBALS['wpas_test_transients']);
        $this->assertStringContainsString('Log intact', $out);
    }

    public function testATamperedChainShowsAsAlteredNotAsIntact(): void
    {
        $this->add('allowed', 'success', 'core/get-site-info', 'user:1');
        $this->add('allowed', 'success', 'core/get-site-info', 'user:1');
        $this->db->exec("UPDATE wp_belayline_audit_log SET record_json = replace(record_json, 'core/get-site-info', 'core/other') WHERE id = 1");

        $out = $this->main($this->page());

        $this->assertStringContainsString('Log altered', $out);
        $this->assertStringContainsString('aui-stat--status is-bad', $out);
        $this->assertStringNotContainsString('Log intact', $out);
    }

    public function testNodeLabelsAreEscaped(): void
    {
        $this->add('allowed', 'success', '<b>evil</b>/x', 'user:1', '<script>alert(1)</script>');

        $out = $this->main($this->page());

        $this->assertStringNotContainsString('<script>alert(1)</script>', $out);
        $this->assertStringNotContainsString('<b>evil</b>', $out);
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $out);
    }

    public function testRailListsRecentCallsInPlainWords(): void
    {
        $this->add('allowed', 'success', 'core/get-site-info', 'app:u1');
        $this->add('denied', null, 'core/get-user-info', 'user:1');
        $this->add('admin', null, 'belayline_shadow_packs', 'user:1');

        ob_start();
        $this->page()->renderRail();
        $out = (string) ob_get_clean();

        $this->assertStringContainsString('Claude ran core/get-site-info<time', $out);
        $this->assertStringContainsString('Stephen was refused: core/get-user-info<time', $out);
        $this->assertStringNotContainsString('belayline_shadow_packs', $out, 'settings changes are not agent calls');
    }

    public function testNothingRendersWithoutTheCapability(): void
    {
        $GLOBALS['wpas_test_user_caps'] = ['manage_options' => false];

        ob_start();
        $this->page()->render();

        $this->assertSame('', (string) ob_get_clean());
    }
}
