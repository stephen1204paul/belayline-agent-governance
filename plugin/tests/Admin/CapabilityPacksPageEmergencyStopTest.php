<?php

declare(strict_types=1);

namespace Belayline\Plugin\Tests\Admin;

use Belayline\Plugin\Tests\Fakes\RedirectException;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Belayline\Plugin\Admin\CapabilityPacksPage;
use Belayline\Plugin\Identity\IdentityChain;
use Belayline\Plugin\Support\AdminChangeRecorder;
use Belayline\Plugin\Support\PackResolver;
use Belayline\Plugin\Support\PauseSwitch;
use Belayline\Plugin\Support\RequestContext;
use Belayline\Plugin\Support\ShadowMode;
use Belayline\Plugin\Tests\Fakes\InMemoryAuditSink;

/**
 * The emergency stop's handlers: pauseAction()/resumeAction() are exercised on
 * their refusal paths and, through the wp_safe_redirect() shim that throws, on
 * their redirect target; success is proven through applyPause()/applyResume(),
 * and the banner (pausedNotice()) by output. The stop control itself lives in the
 * shell, see ShellTest. What a pause does to a governed call
 * is proven in {@see \Belayline\Plugin\Tests\Verdict\VerdictPipelineStopsTest};
 * the switch itself in {@see \Belayline\Plugin\Tests\Support\PauseSwitchTest}.
 */
final class CapabilityPacksPageEmergencyStopTest extends TestCase
{
    private const NOW = 1_800_000_000;

    private InMemoryAuditSink $sink;
    private PauseSwitch $pause;
    private CapabilityPacksPage $page;

    protected function setUp(): void
    {
        $GLOBALS['wpas_test_options'] = [];
        $GLOBALS['wpas_test_time'] = self::NOW;
        $GLOBALS['wpas_test_current_user_id'] = 7;
        $GLOBALS['wpas_test_user_caps'] = ['manage_options' => true];
        $_POST = [];
        $_GET = [];
        remove_all_filters(ShadowMode::FILTER);
        remove_all_filters(PauseSwitch::FILTER);
        RequestContext::reset();

        $this->sink = new InMemoryAuditSink();
        $this->pause = new PauseSwitch(new AdminChangeRecorder($this->sink));
        $this->page = new CapabilityPacksPage(
            new PackResolver(),
            new IdentityChain(),
            new ShadowMode(),
            new AdminChangeRecorder($this->sink),
            $this->pause,
        );
    }

    protected function tearDown(): void
    {
        $GLOBALS['wpas_test_options'] = [];
        $GLOBALS['wpas_test_time'] = \time();
        $GLOBALS['wpas_test_current_user_id'] = 0;
        $GLOBALS['wpas_test_user_caps'] = [];
        $_POST = [];
        $_GET = [];
        remove_all_filters(ShadowMode::FILTER);
        remove_all_filters(PauseSwitch::FILTER);
        RequestContext::reset();
    }

    // --- pauseAction() refusals -----------------------------------------------

    public function testPauseActionWithoutTheCapabilityDiesAndPausesNothing(): void
    {
        $GLOBALS['wpas_test_user_caps'] = ['manage_options' => false];
        $_POST = ['_wpnonce' => 'test-nonce', 'reason' => 'runaway bot'];

        try {
            $this->page->pauseAction();
            $this->fail('expected wp_die to throw');
        } catch (RuntimeException $e) {
            $this->assertStringStartsWith('wp_die:', $e->getMessage());
        }

        $this->assertFalse($this->pause->isPaused());
        $this->assertNull($this->pause->state());
        $this->assertSame([], $this->sink->records, 'a refused pause audits nothing');
    }

    public function testPauseActionWithMissingNonceDiesAndPausesNothing(): void
    {
        $_POST = ['reason' => 'runaway bot']; // no _wpnonce at all

        try {
            $this->page->pauseAction();
            $this->fail('expected wp_die to throw');
        } catch (RuntimeException $e) {
            $this->assertStringStartsWith('wp_die:', $e->getMessage());
        }

        $this->assertFalse($this->pause->isPaused());
        $this->assertSame([], $this->sink->records);
    }

    public function testPauseActionWithWrongNonceDiesAndPausesNothing(): void
    {
        $_POST = ['_wpnonce' => 'not-the-right-nonce', 'reason' => 'runaway bot'];

        try {
            $this->page->pauseAction();
            $this->fail('expected wp_die to throw');
        } catch (RuntimeException $e) {
            $this->assertStringStartsWith('wp_die:', $e->getMessage());
        }

        $this->assertFalse($this->pause->isPaused());
        $this->assertSame([], $this->sink->records);
    }

    // --- sanitizePauseReason(): §3.6.10 -----------------------------------------

    public function testSanitizePauseReasonGivesEmptyStringForANonScalarValue(): void
    {
        $this->assertSame('', CapabilityPacksPage::sanitizePauseReason(['array' => 'is not scalar']));
        $this->assertSame('', CapabilityPacksPage::sanitizePauseReason(null));
    }

    public function testSanitizePauseReasonKeepsAnOrdinaryString(): void
    {
        $this->assertSame('runaway bot', CapabilityPacksPage::sanitizePauseReason('runaway bot'));
    }

    public function testSanitizePauseReasonStripsTagsAndTrimsWhitespace(): void
    {
        $this->assertSame(
            'alert(1)',
            CapabilityPacksPage::sanitizePauseReason('  <script>alert(1)</script>  ')
        );
    }

    public function testSanitizePauseReasonUnslashesBeforeSanitising(): void
    {
        $this->assertSame(
            "it's stuck",
            CapabilityPacksPage::sanitizePauseReason(addslashes("it's stuck"))
        );
    }

    // --- resumeAction() refusals: a stored pause stays in place ---------------

    public function testResumeActionWithoutTheCapabilityDiesAndLeavesTheStoredPauseInPlace(): void
    {
        $this->pause->pause(7, 'runaway bot');
        $this->sink->records = []; // isolate the resume attempt from the setup pause
        $GLOBALS['wpas_test_user_caps'] = ['manage_options' => false];
        $_POST = ['_wpnonce' => 'test-nonce'];

        try {
            $this->page->resumeAction();
            $this->fail('expected wp_die to throw');
        } catch (RuntimeException $e) {
            $this->assertStringStartsWith('wp_die:', $e->getMessage());
        }

        $this->assertTrue($this->pause->isPaused());
        $this->assertNotNull($this->pause->state());
        $this->assertSame([], $this->sink->records, 'a refused resume audits nothing');
    }

    public function testResumeActionWithMissingOrWrongNonceDiesAndLeavesTheStoredPauseInPlace(): void
    {
        $this->pause->pause(7, 'runaway bot');
        $this->sink->records = [];
        $_POST = ['_wpnonce' => 'nope'];

        try {
            $this->page->resumeAction();
            $this->fail('expected wp_die to throw');
        } catch (RuntimeException $e) {
            $this->assertStringStartsWith('wp_die:', $e->getMessage());
        }

        $this->assertTrue($this->pause->isPaused());
        $this->assertSame([], $this->sink->records);
    }

    // --- applyPause() / applyResume(): the success paths -----------------------

    public function testApplyPauseStoresTheReasonAndPausesAsTheLoggedInUser(): void
    {
        $this->page->applyPause('runaway fulfilment bot');

        $stored = ['since' => self::NOW, 'by' => 7, 'reason' => 'runaway fulfilment bot'];
        $this->assertSame($stored, $GLOBALS['wpas_test_options'][PauseSwitch::OPTION]);
        $this->assertTrue($this->pause->isPaused());

        $this->assertCount(1, $this->sink->records);
        $row = $this->sink->records[0]->toArray();
        $this->assertSame(AdminChangeRecorder::EVENT_PAUSE_ENABLED, $row['reason']);
        $this->assertSame(7, $row['actor']['wp_user']);
    }

    public function testApplyResumeClearsAStoredPauseAsTheLoggedInUser(): void
    {
        $this->pause->pause(7, 'x');
        $this->sink->records = [];
        $GLOBALS['wpas_test_current_user_id'] = 9;

        $this->page->applyResume();

        $this->assertArrayNotHasKey(PauseSwitch::OPTION, $GLOBALS['wpas_test_options']);
        $this->assertFalse($this->pause->isPaused());
        $this->assertCount(1, $this->sink->records);
        $this->assertSame(9, $this->sink->records[0]->toArray()['actor']['wp_user']);
    }

    // --- pausedNotice(): output (the stop control itself is tested in ShellTest) -------------------------

    public function testPausedNoticeIsSilentForAUserWithoutTheCapability(): void
    {
        $this->pause->pause(7, 'x');
        $GLOBALS['wpas_test_user_caps'] = ['manage_options' => false];

        ob_start();
        $this->page->pausedNotice();
        $out = (string) ob_get_clean();

        $this->assertSame('', $out);
    }

    public function testPausedNoticeIsSilentWhenNotPaused(): void
    {
        ob_start();
        $this->page->pausedNotice();
        $out = (string) ob_get_clean();

        $this->assertSame('', $out);
    }

    public function testPausedNoticeShowsForACapableUserWhenPaused(): void
    {
        $this->pause->pause(7, 'x');

        ob_start();
        $this->page->pausedNotice();
        $out = (string) ob_get_clean();

        $this->assertStringContainsString('Belayline Agent Governance: all agent actions are paused.', $out);
        $this->assertStringContainsString('Review or resume', $out);
    }

    // --- redirect target ------------------------------------------------------

    public function testPauseActionReturnsToTheTabItWasPressedOn(): void
    {
        $_POST = ['_wpnonce' => 'test-nonce', 'reason' => 'runaway bot', 'belayline_tab' => 'pending'];

        try {
            $this->page->pauseAction();
            $this->fail('expected the redirect shim to throw');
        } catch (RedirectException $redirect) {
            $this->assertSame('https://example.test/wp-admin/admin.php?page=belayline-pending', $redirect->location);
        }

        $this->assertTrue($this->pause->isPaused());
    }

    public function testResumeActionFallsBackToTheOverviewForAnUnknownTab(): void
    {
        $this->pause->pause(7, 'x');
        $_POST = ['_wpnonce' => 'test-nonce', 'belayline_tab' => 'https://evil.example/'];

        try {
            $this->page->resumeAction();
            $this->fail('expected the redirect shim to throw');
        } catch (RedirectException $redirect) {
            $this->assertSame('https://example.test/wp-admin/admin.php?page=belayline', $redirect->location);
        }

        $this->assertFalse($this->pause->isPaused());
    }
}
