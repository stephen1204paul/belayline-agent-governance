<?php

declare(strict_types=1);

namespace Belayline\Plugin\Tests\Admin;

use Belayline\Plugin\Admin\AdminApp;
use Belayline\Plugin\Admin\AdminUrls;
use Belayline\Plugin\Admin\CapabilityPacksPage;
use Belayline\Plugin\Admin\SettingsPage;
use Belayline\Plugin\Audit\WpdbApprovalStore;
use Belayline\Plugin\Identity\IdentityChain;
use Belayline\Plugin\Support\AdminChangeRecorder;
use Belayline\Plugin\Support\PackResolver;
use Belayline\Plugin\Support\PauseSwitch;
use Belayline\Plugin\Support\ShadowMode;
use PHPUnit\Framework\TestCase;
use Belayline\Plugin\Tests\Fakes\RedirectException;
use wpdb;

/** One top-level menu, five tabs, kit assets on those screens only, and the old Tools URLs. */
final class AdminAppTest extends TestCase
{
    private const FILE = __DIR__ . '/../../belayline-agent-governance.php';

    protected function setUp(): void
    {
        $GLOBALS['wpas_test_options'] = [];
        $GLOBALS['wpas_test_user_caps'] = ['manage_options' => true];
        $GLOBALS['wpas_test_menu_pages'] = [];
        $GLOBALS['wpas_test_submenu_pages'] = [];
        $GLOBALS['wpas_test_enqueued'] = [];
        $GLOBALS['wpas_test_redirects'] = [];
        unset($GLOBALS['pagenow']);
        $_GET = [];
        remove_all_filters(PauseSwitch::FILTER);
    }

    protected function tearDown(): void
    {
        $GLOBALS['wpas_test_user_caps'] = [];
        unset($GLOBALS['pagenow']);
        $_GET = [];
        remove_all_filters(PauseSwitch::FILTER);
    }

    private function app(int $pending = 0): AdminApp
    {
        $db = new wpdb();
        $db->varReturn = $pending;
        $changes = new AdminChangeRecorder(null);
        $packs = new CapabilityPacksPage(new PackResolver(), new IdentityChain(), new ShadowMode(), $changes, new PauseSwitch($changes));

        return new AdminApp(self::FILE, new PauseSwitch($changes), new ShadowMode(), new WpdbApprovalStore($db), $packs, new SettingsPage($packs));
    }

    public function testOneTopLevelMenuNearToolsWithAnSvgIconAndFiveTabs(): void
    {
        $this->app()->menu();

        $top = $GLOBALS['wpas_test_menu_pages'];
        $this->assertSame(['belayline'], array_keys($top));
        $this->assertSame('manage_options', $top['belayline']['cap']);
        $this->assertSame('Belayline', $top['belayline']['menu']);
        $this->assertSame(76, $top['belayline']['position']);
        $this->assertGreaterThan(75, $top['belayline']['position'], 'after Tools');
        $this->assertLessThan(80, $top['belayline']['position'], 'before Settings');

        $subs = $GLOBALS['wpas_test_submenu_pages']['belayline'];
        $this->assertSame(['belayline', 'belayline-pending', 'belayline-activity', 'belayline-packs', 'belayline-settings'], array_keys($subs));
        $this->assertSame(['Overview', 'Pending', 'Activity', 'Packs', 'Settings'], array_column($subs, 'title'));
        foreach ($subs as $sub) {
            $this->assertSame('manage_options', $sub['cap']);
        }
        $this->assertArrayNotHasKey('wpas_test_management_pages', $GLOBALS, 'nothing is added under Tools any more');
    }

    public function testTheMenuIconIsABundledSvgDataUriThatWordPressCanRecolour(): void
    {
        $icon = AdminApp::menuIcon();

        $this->assertStringStartsWith('data:image/svg+xml;base64,', $icon);
        $svg = (string) base64_decode(substr($icon, strlen('data:image/svg+xml;base64,')), true);
        $this->assertStringStartsWith('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="#a7aaad">', $svg);
        $this->assertNotFalse(simplexml_load_string($svg), 'well-formed SVG');
        $this->assertStringNotContainsString('http://', str_replace('http://www.w3.org/2000/svg', '', $svg), 'no remote reference');
        $this->assertSame($icon, $GLOBALS['wpas_test_menu_pages']['belayline']['icon'] ?? $icon);
    }

    public function testPendingGetsACountBubbleLikeCoresUpdateCount(): void
    {
        $this->app(3)->menu();

        $pending = $GLOBALS['wpas_test_submenu_pages']['belayline']['belayline-pending']['menu'];
        $this->assertStringContainsString('<span class="awaiting-mod count-3">', $pending);
        $this->assertStringContainsString('3 actions waiting', $pending);

        $GLOBALS['wpas_test_submenu_pages'] = [];
        $this->app(0)->menu();
        $this->assertSame('Pending', $GLOBALS['wpas_test_submenu_pages']['belayline']['belayline-pending']['menu']);
    }

    /** @return array<string, array{0: string, 1: array<string, string>, 2: string}> */
    public static function legacyUrls(): array
    {
        return [
            'pending' => ['belayline-pending', [], 'https://example.test/wp-admin/admin.php?page=belayline-pending'],
            'packs, with the setup-link arguments' => [
                'belayline-packs',
                ['belayline_bind_token' => 'app:abc', 'belayline_bind_pack' => 'owner'],
                'https://example.test/wp-admin/admin.php?page=belayline-packs&belayline_bind_token=app%3Aabc&belayline_bind_pack=owner',
            ],
            'abilities lands on its section of Packs' => ['belayline-abilities', ['belayline_saved' => '1'], 'https://example.test/wp-admin/admin.php?page=belayline-packs&belayline_saved=1#abilities'],
            'audit log is Activity, Calls' => ['belayline-audit', ['paged' => '3'], 'https://example.test/wp-admin/admin.php?page=belayline-activity&section=calls&paged=3'],
            'changes is Activity, Changes, nonce and scope kept' => [
                'belayline-changes',
                ['view' => 'preview', 'scope' => 'event', 'scope_id' => 'evt-1', '_wpnonce' => 'n0nce'],
                'https://example.test/wp-admin/admin.php?page=belayline-activity&section=changes&view=preview&scope=event&scope_id=evt-1&_wpnonce=n0nce',
            ],
        ];
    }

    /**
     * @dataProvider legacyUrls
     * @param array<string, string> $args
     */
    public function testOldToolsUrlsMapToTheNewTabsKeepingEveryOtherArgument(string $page, array $args, string $expected): void
    {
        $this->assertSame($expected, $this->app()->legacyTarget(['page' => $page] + $args, 'tools.php'));
    }

    public function testOnlyToolsPhpRequestsForOurOldSlugsAreRedirected(): void
    {
        $app = $this->app();

        $this->assertNull($app->legacyTarget(['page' => 'belayline-pending'], 'admin.php'), 'the new page of the same name is not a loop');
        $this->assertNull($app->legacyTarget(['page' => 'something-else'], 'tools.php'));
        $this->assertNull($app->legacyTarget([], 'tools.php'));
        $this->assertNull($app->legacyTarget(['page' => ['belayline-pending']], 'tools.php'));
    }

    public function testTheRedirectIsA302ThroughWpSafeRedirect(): void
    {
        $GLOBALS['pagenow'] = 'tools.php';
        $_GET = ['page' => 'belayline-audit', 'paged' => '2'];

        try {
            $this->app()->redirectLegacy();
            $this->fail('expected a redirect');
        } catch (RedirectException $redirect) {
            $this->assertSame(302, $redirect->status);
            $this->assertSame('https://example.test/wp-admin/admin.php?page=belayline-activity&section=calls&paged=2', $redirect->location);
        }
    }

    public function testNoRedirectOnOtherAdminPages(): void
    {
        $GLOBALS['pagenow'] = 'index.php';
        $_GET = ['page' => 'belayline-audit'];

        $this->app()->redirectLegacy();

        $this->assertSame([], $GLOBALS['wpas_test_redirects']);
    }

    public function testEveryLegacySlugIsCoveredAndPointsAtARealTab(): void
    {
        $this->assertSame(
            ['belayline-pending', 'belayline-packs', 'belayline-abilities', 'belayline-audit', 'belayline-changes'],
            array_keys(AdminUrls::LEGACY)
        );
        foreach (AdminUrls::LEGACY as [$tab]) {
            $this->assertArrayHasKey($tab, AdminUrls::SLUGS);
        }
    }

    public function testAssetsLoadOnBelaylineScreensOnlyWithBelaylinePrefixedHandles(): void
    {
        $app = $this->app();
        $app->menu();

        $app->assets('edit.php');
        $app->assets('tools_page_something-else');
        $this->assertSame([], $GLOBALS['wpas_test_enqueued'], 'nothing loads elsewhere in wp-admin');

        $app->assets('belayline_page_belayline-packs');
        $enqueued = $GLOBALS['wpas_test_enqueued'];
        foreach (['style', 'script'] as $type) {
            foreach (array_keys($enqueued[$type]) as $handle) {
                $this->assertStringStartsWith('belayline-', $handle);
            }
        }
        $this->assertSame(['belayline-aui-tokens', 'belayline-aui', 'belayline-admin'], array_keys($enqueued['style']));
        $this->assertSame(['belayline-aui', 'belayline-admin'], array_keys($enqueued['script']), 'the map script is Overview-only');
        $this->assertStringEndsWith('/assets/vendor/admin-ui-kit/components.css', $enqueued['style']['belayline-aui']['src']);

        $GLOBALS['wpas_test_enqueued'] = [];
        $app->assets('toplevel_page_belayline');
        $this->assertArrayHasKey('belayline-aui-map', $GLOBALS['wpas_test_enqueued']['script']);
        $this->assertContains('belayline-aui-map', $GLOBALS['wpas_test_enqueued']['script']['belayline-admin']['deps']);
    }

    public function testRenderTabPutsTheTabInsideTheShell(): void
    {
        ob_start();
        $this->app()->renderTab('settings');
        $out = (string) ob_get_clean();

        $this->assertStringContainsString('class="aui belayline-aui"', $out);
        $this->assertMatchesRegularExpression('#aria-current="page">Settings</a>#', $out);
        $this->assertStringContainsString('Emergency stop', $out);
        $this->assertStringContainsString('name="ip_allowlist"', $out);
    }

    public function testATabWithoutItsStoreSaysSoInsteadOfFailingSilently(): void
    {
        ob_start();
        $this->app()->renderTab('pending');
        $out = (string) ob_get_clean();

        $this->assertStringContainsString('This screen is not available', $out);
        $this->assertStringContainsString('Emergency stop', $out, 'the stop is still reachable');
    }

    public function testNothingRendersForAUserWithoutTheCapability(): void
    {
        $GLOBALS['wpas_test_user_caps'] = ['manage_options' => false];

        ob_start();
        $this->app()->renderTab('overview');

        $this->assertSame('', (string) ob_get_clean());
    }
}
