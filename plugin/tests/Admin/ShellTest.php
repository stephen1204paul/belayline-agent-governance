<?php

declare(strict_types=1);

namespace Belayline\Plugin\Tests\Admin;

use Belayline\Plugin\Admin\Ui\Shell;
use Belayline\Plugin\Support\AdminChangeRecorder;
use Belayline\Plugin\Support\PauseSwitch;
use Belayline\Plugin\Support\ShadowMode;
use Belayline\Plugin\Audit\WpdbApprovalStore;
use PHPUnit\Framework\TestCase;
use wpdb;

/** The frame around every tab: header, status pill, tabs with the pending count, and the emergency stop. */
final class ShellTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['wpas_test_options'] = [];
        $GLOBALS['wpas_test_time'] = 1_800_000_000;
        remove_all_filters(PauseSwitch::FILTER);
        remove_all_filters(ShadowMode::FILTER);
    }

    protected function tearDown(): void
    {
        $GLOBALS['wpas_test_options'] = [];
        $GLOBALS['wpas_test_time'] = time();
        remove_all_filters(PauseSwitch::FILTER);
        remove_all_filters(ShadowMode::FILTER);
    }

    private function render(string $tab = 'overview', int $pending = 0, ?PauseSwitch $pause = null, ?ShadowMode $shadow = null): string
    {
        $db = new wpdb();
        $db->varReturn = $pending;
        $shell = new Shell($pause ?? new PauseSwitch(new AdminChangeRecorder(null)), $shadow ?? new ShadowMode(), new WpdbApprovalStore($db), '0.5.0');

        ob_start();
        $shell->render($tab, static function (): void {
            echo '<p>MAIN</p>';
        }, static function (): void {
            echo '<p>RAIL</p>';
        });

        return (string) ob_get_clean();
    }

    public function testHeaderHasTheMarkFiveTabsTheStatusPillAndTheVersionChip(): void
    {
        $out = $this->render('packs');

        $this->assertStringContainsString('class="aui belayline-aui" data-brand="belayline"', $out);
        $this->assertStringContainsString('<h1>Belayline</h1>', $out);
        foreach (['belayline', 'belayline-pending', 'belayline-activity', 'belayline-packs', 'belayline-settings'] as $slug) {
            $this->assertStringContainsString('href="https://example.test/wp-admin/admin.php?page=' . $slug . '"', $out);
        }
        $this->assertSame(1, substr_count($out, 'aria-current="page"'));
        $this->assertMatchesRegularExpression('#aria-current="page">Packs</a>#', $out);
        $this->assertStringContainsString('aui-pill--ok">Guarding', $out);
        $this->assertStringContainsString('<span class="aui-chip">v0.5.0</span>', $out);
        $this->assertStringContainsString('<hr class="wp-header-end">', $out);
        $this->assertStringContainsString('<p>MAIN</p>', $out);
        $this->assertStringContainsString('<p>RAIL</p>', $out);
        $this->assertStringContainsString('<symbol id="aui-i-shield"', $out, 'the icon sprite is printed inline once');
        $this->assertSame(1, substr_count($out, '<svg xmlns="http://www.w3.org/2000/svg" style="display:none">'));
    }

    public function testThePendingTabShowsTheWaitingCountOnlyWhenThereIsOne(): void
    {
        $this->assertStringNotContainsString('aui-count', $this->render('overview', 0));
        $this->assertMatchesRegularExpression('#Pending <span class="aui-count aui-count--warn">3</span>#', $this->render('overview', 3));
    }

    public function testEveryTabCarriesTheEmergencyStopWithTheTabItReturnsTo(): void
    {
        foreach (['overview', 'pending', 'activity', 'packs', 'settings'] as $tab) {
            $out = $this->render($tab);

            $this->assertStringContainsString('Emergency stop', $out, $tab);
            $this->assertStringContainsString('data-aui-open="belayline-stop"', $out, $tab);
            $this->assertStringContainsString('value="belayline_pause"', $out, $tab);
            $this->assertStringContainsString('name="belayline_tab" value="' . $tab . '"', $out, $tab);
            $this->assertStringContainsString('role="dialog" aria-modal="true"', $out, $tab);
            // Stopping is never harder than clicking: the reason is optional.
            $this->assertDoesNotMatchRegularExpression('#name="reason"[^>]*required#', $out, $tab);
        }
    }

    public function testWhilePausedTheRailOffersResumeAndThePillSaysPaused(): void
    {
        $pause = new PauseSwitch(new AdminChangeRecorder(null));
        $pause->pause(7, 'runaway bot');

        $out = $this->render('overview', 0, $pause);

        $this->assertStringContainsString('aui-pill--bad">Paused', $out);
        $this->assertStringContainsString('All agent actions are paused', $out);
        $this->assertStringContainsString('Since ' . gmdate('Y-m-d H:i', 1_800_000_000) . ' UTC by user #7. Reason: runaway bot', $out);
        $this->assertStringContainsString('value="belayline_resume"', $out);
        $this->assertStringNotContainsString('data-aui-open="belayline-stop"', $out);
        $this->assertStringNotContainsString('value="belayline_pause"', $out);
    }

    public function testAFilterForcedPauseShowsNoResume(): void
    {
        add_filter(PauseSwitch::FILTER, static fn (): bool => true);

        $out = $this->render();

        $this->assertStringContainsString('All agent actions are paused', $out);
        $this->assertStringContainsString('the ' . PauseSwitch::FILTER . ' filter', $out);
        $this->assertStringNotContainsString('value="belayline_resume"', $out);
        $this->assertStringNotContainsString('Resume agent actions', $out);
    }

    public function testAnInjectedReasonIsEscaped(): void
    {
        $pause = new PauseSwitch(new AdminChangeRecorder(null));
        $pause->pause(7, '<script>alert(1)</script>');

        $out = $this->render('overview', 0, $pause);

        $this->assertStringNotContainsString('<script>alert(1)</script>', $out);
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $out);
    }

    public function testShadowModeShowsInTheHeaderWhenAPackIsWatchOnly(): void
    {
        $GLOBALS['wpas_test_options'][ShadowMode::OPTION] = ['owner' => 1_800_000_000 + 3600];

        $this->assertStringContainsString('aui-pill--warn">Shadow mode', $this->render());
    }

    public function testPausedWinsOverShadowInThePill(): void
    {
        $GLOBALS['wpas_test_options'][ShadowMode::OPTION] = ['owner' => 1_800_000_000 + 3600];
        $pause = new PauseSwitch(new AdminChangeRecorder(null));
        $pause->pause(7, '');

        $out = $this->render('overview', 0, $pause);

        $this->assertStringContainsString('aui-pill--bad">Paused', $out);
        $this->assertStringNotContainsString('Shadow mode', $out);
    }
}
