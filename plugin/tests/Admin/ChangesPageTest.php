<?php

declare(strict_types=1);

namespace Specflux\AgentSafety\Plugin\Tests\Admin;

use PHPUnit\Framework\TestCase;
use Specflux\AgentSafety\Changes\CaptureWindow;
use Specflux\AgentSafety\Changes\Change;
use Specflux\AgentSafety\Changes\ChangeAction;
use Specflux\AgentSafety\Changes\UndoResult;
use Specflux\AgentSafety\Plugin\Admin\ChangesPage;
use Specflux\AgentSafety\Plugin\Audit\AuditReader;
use Specflux\AgentSafety\Plugin\Changes\ChangeCapture;
use Specflux\AgentSafety\Plugin\Changes\UndoService;
use Specflux\AgentSafety\Plugin\Support\PackResolver;
use Specflux\AgentSafety\Plugin\Support\RequestContext;
use Specflux\AgentSafety\Plugin\Tests\Fakes\InMemoryChangeLedger;
use Specflux\AgentSafety\Policy\Tier;
use Specflux\AgentSafety\Policy\TierClassifier;
use Specflux\AgentSafety\Policy\VerbCatalog;
use wpdb;

final class ChangesPageTest extends TestCase
{
    private InMemoryChangeLedger $ledger;
    private ChangeCapture $capture;
    private ChangesPage $page;
    private wpdb $db;

    protected function setUp(): void
    {
        RequestContext::reset();
        ChangeCapture::configure(null);
        $GLOBALS['wpas_test_posts'] = [];
        $GLOBALS['wpas_test_meta'] = [];
        $GLOBALS['wpas_test_options'] = [];
        $GLOBALS['wpas_test_transients'] = [];
        $GLOBALS['wpas_test_added_actions'] = [];
        $GLOBALS['wpas_test_current_user_id'] = 1;
        $GLOBALS['wpas_test_user_caps'] = ['manage_options' => true];
        $GLOBALS['wpas_test_user_caps_by_id'] = [1 => ['manage_options' => true]];
        $_GET = [];
        $_POST = [];

        $catalog = new VerbCatalog();
        $catalog->register(['t/write' => Tier::SideEffecting]);
        $this->ledger = new InMemoryChangeLedger();
        $this->capture = new ChangeCapture(new TierClassifier($catalog), new PackResolver(), $this->ledger);
        $this->db = new wpdb();
        $this->page = new ChangesPage($this->ledger, new UndoService($this->ledger, $this->capture->adapters(), $this->capture), new AuditReader($this->db));
    }

    protected function tearDown(): void
    {
        RequestContext::reset();
        $_GET = [];
        $_POST = [];
        $GLOBALS['wpas_test_user_caps'] = [];
        $GLOBALS['wpas_test_user_caps_by_id'] = [];
        $GLOBALS['wpas_test_current_user_id'] = 0;
    }

    private static function seedPost(int $id, string $title): void
    {
        $GLOBALS['wpas_test_posts'][$id] = (object) [
            'ID' => $id, 'post_title' => $title, 'post_type' => 'post', 'post_status' => 'publish',
            'post_password' => '', 'post_modified' => '2026-01-01 00:00:00',
        ];
    }

    private function retitle(int $id, string $title): string
    {
        $this->capture->run('t/write', [], static fn () => wp_update_post(wp_slash(['ID' => $id, 'post_title' => $title])));

        return (string) $this->capture->takeSettled('t/write')['event_id'];
    }

    private function seedIrreversible(string $event, string $corr): void
    {
        $window = new CaptureWindow();
        $window->open($event, $corr, false);
        $window->record(Change::capture('order', '9', null, ChangeAction::Irreversible, ['status' => 'a'], ['status' => 'b']));
        $closed = $window->close();
        $this->assertNotNull($closed);
        $this->ledger->insertWindow($closed, '2026-01-01 00:00:00');
    }

    private function html(): string
    {
        ob_start();
        $this->page->render();

        return (string) ob_get_clean();
    }

    public function testListingGroupsEventsUnderTheirRunWithFieldDiffs(): void
    {
        self::seedPost(7, 'A');
        $e1 = $this->retitle(7, 'B');
        $e2 = $this->retitle(7, 'C');
        $corr = $this->ledger->forEvent($e1)[0]->correlationId;
        $this->seedIrreversible('evt-other', 'corr-other');

        $out = $this->html();

        $this->assertSame(1, substr_count($out, '<code>' . $corr . '</code>'), 'the run heading appears once');
        $this->assertStringContainsString('Undo this run', $out);
        $this->assertStringContainsString($e1, $out);
        $this->assertStringContainsString($e2, $out);
        $this->assertStringContainsString('post_title', $out);
        $this->assertStringContainsString('<del><code>A</code></del>', $out);
        $this->assertStringContainsString('<ins><code>B</code></ins>', $out);
        $this->assertStringContainsString('corr-other', $out);
    }

    public function testIrreversibleRowsShowTheReasonAndNoUndoButton(): void
    {
        $this->seedIrreversible('evt-x', 'corr-x');

        $out = $this->html();

        $this->assertStringContainsString('Irreversible by nature', $out);
        $this->assertStringNotContainsString('Undo this change', $out);
        $this->assertStringContainsString('Undo this event', $out, 'the event-level link remains; the preview will skip it');
    }

    public function testPreviewShowsCountsAndSkipReasons(): void
    {
        self::seedPost(7, 'A');
        $event = $this->retitle(7, 'B');
        $corr = $this->ledger->forEvent($event)[0]->correlationId;
        $this->seedIrreversible('evt-irr', $corr);

        $_GET = ['view' => 'preview', 'scope' => 'correlation', 'scope_id' => $corr, '_wpnonce' => 'test-nonce'];
        $out = $this->html();

        $this->assertStringContainsString('1 can be restored, 1 will be skipped, 0 would stop the undo.', $out);
        $this->assertStringContainsString('Skipped: Irreversible by nature', $out);
        $this->assertStringContainsString('Will be restored', $out);
        $this->assertStringContainsString('<ins><code>A</code></ins>', $out, 'preview shows the value coming back');
        $this->assertStringContainsString('Confirm undo', $out);
    }

    public function testPreviewRefusesABadNonce(): void
    {
        $_GET = ['view' => 'preview', 'scope' => 'event', 'scope_id' => 'e', '_wpnonce' => 'bad'];
        $level = ob_get_level();
        try {
            $this->html();
            $this->fail('expected wp_die');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('expired', $e->getMessage());
        } finally {
            while (ob_get_level() > $level) {
                ob_end_clean();
            }
        }
    }

    public function testUndoRequiresANonce(): void
    {
        self::seedPost(7, 'A');
        $event = $this->retitle(7, 'B');
        $_POST = ['scope' => 'event', 'scope_id' => $event, '_wpnonce' => 'bad'];

        try {
            $this->page->process();
            $this->fail('expected wp_die');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('expired', $e->getMessage());
        }
        $this->assertSame('B', $GLOBALS['wpas_test_posts'][7]->post_title);
    }

    public function testUndoRequiresTheCapability(): void
    {
        self::seedPost(7, 'A');
        $event = $this->retitle(7, 'B');
        $GLOBALS['wpas_test_user_caps'] = [];
        $_POST = ['scope' => 'event', 'scope_id' => $event, '_wpnonce' => 'test-nonce'];

        try {
            $this->page->process();
            $this->fail('expected wp_die');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Insufficient', $e->getMessage());
        }
        $this->assertSame('B', $GLOBALS['wpas_test_posts'][7]->post_title);
    }

    public function testConfirmedUndoRestoresAndReportsRestoredAndSkipped(): void
    {
        self::seedPost(7, 'A');
        $event = $this->retitle(7, 'B');
        $corr = $this->ledger->forEvent($event)[0]->correlationId;
        $this->seedIrreversible('evt-irr', $corr);
        $_POST = ['scope' => 'correlation', 'scope_id' => $corr, '_wpnonce' => 'test-nonce'];

        $result = $this->page->process();

        $this->assertSame(UndoResult::COMPLETE, $result->status);
        $this->assertSame('A', $GLOBALS['wpas_test_posts'][7]->post_title);
        $out = $this->html();
        $this->assertStringContainsString('Undo complete.', $out);
        $this->assertStringContainsString('Restored (1)', $out);
        $this->assertStringContainsString('Skipped (1)', $out);
        $this->assertStringNotContainsString('Undo complete.', $this->html(), 'the notice shows once');
    }

    public function testDriftResultRendersTheThreeWayDifference(): void
    {
        self::seedPost(7, 'A');
        $event = $this->retitle(7, 'B');
        $GLOBALS['wpas_test_posts'][7]->post_title = 'C';
        $_POST = ['scope' => 'event', 'scope_id' => $event, '_wpnonce' => 'test-nonce'];

        $result = $this->page->process();

        $this->assertSame(UndoResult::REFUSED, $result->status);
        $out = $this->html();
        $this->assertStringContainsString('Stopped at (1)', $out);
        $this->assertStringContainsString('Changed since the agent ran', $out);
        $this->assertStringContainsString('Before the agent', $out);
        foreach (['A', 'B', 'C'] as $v) {
            $this->assertStringContainsString('<td><code>' . $v . '</code></td>', $out);
        }
        $this->assertSame('C', $GLOBALS['wpas_test_posts'][7]->post_title);
    }

    public function testValuesAreEscaped(): void
    {
        self::seedPost(7, 'A');
        $this->retitle(7, '<script>alert(1)</script>');

        $out = $this->html();

        $this->assertStringNotContainsString('<script>', $out);
        $this->assertStringContainsString('&lt;script&gt;', $out);
    }

    public function testMenuIsRegisteredForManageOptions(): void
    {
        $this->page->menu();
        $this->assertSame('manage_options', $GLOBALS['wpas_test_management_pages'][ChangesPage::SLUG]);
    }
}
