<?php

declare(strict_types=1);

namespace Belayline\Plugin\Tests\Admin;

use Belayline\Plugin\Admin\Map\WhoResolver;
use Belayline\Plugin\Admin\PendingActionsPage;
use Belayline\Plugin\Api\Approvals;
use Belayline\Plugin\Audit\AuditReader;
use Belayline\Plugin\Audit\WpdbApprovalStore;
use Belayline\Plugin\Tests\Fakes\RedirectException;
use PHPUnit\Framework\TestCase;
use wpdb;

/** The Pending tab: one card per waiting action, wired to the existing approve / reject handlers. */
final class PendingActionsPageCardsTest extends TestCase
{
    private const NOW = 1_800_000_000;

    protected function setUp(): void
    {
        $GLOBALS['wpas_test_user_caps'] = ['manage_options' => true];
        $GLOBALS['wpas_test_options'] = [];
        $GLOBALS['wpas_test_transients'] = [];
        $_POST = [];
    }

    protected function tearDown(): void
    {
        $GLOBALS['wpas_test_user_caps'] = [];
    }

    /** @param list<array<string, mixed>> $rows */
    private function render(array $rows, array $auditRows = []): string
    {
        $db = new wpdb();
        $db->resultsReturn = $rows;
        $store = new WpdbApprovalStore($db);
        $auditDb = new wpdb();
        $auditDb->resultsReturn = $auditRows;
        $who = new WhoResolver(static fn (): array => ['app:u1' => 'Claude'], static fn (int $id): ?string => 'Stephen', static fn (array $ids): array => []);
        $page = new PendingActionsPage($store, new Approvals($store), who: $who, audit: new AuditReader($auditDb), now: self::NOW);

        ob_start();
        $page->render();

        return (string) ob_get_clean();
    }

    private static function row(string $id = 'apr_1'): array
    {
        return [
            'approval_id' => $id, 'verb' => 'woocommerce/order-refund', 'summary' => 'Refund order #1043', 'correlation_id' => 'corr_1',
            'audit_event_id' => 'evt_1', 'key_id' => 'app:u1', 'fingerprint_kind' => 'probe',
            'created_ts' => gmdate('Y-m-d H:i:s', self::NOW - 3600), 'pending_expires_ts' => gmdate('Y-m-d H:i:s', self::NOW + 6 * 86400 + 23 * 3600), 'status' => 'pending',
        ];
    }

    public function testACardShowsWhoWhatWhyExpiryAndTheTwoExistingActions(): void
    {
        $out = $this->render([self::row()], [['event_id' => 'evt_1', 'tier' => 2, 'pack' => 'commerce', 'ability' => 'woocommerce/order-refund']]);

        $this->assertStringContainsString('Claude <span class="aui-muted">&middot; AI app</span>', $out);
        $this->assertStringContainsString('>Refund order #1043</h2>', $out);
        $this->assertStringContainsString('always asks first, in the Commerce pack', $out);
        $this->assertStringContainsString('in 6 d 23 h', $out);
        $this->assertStringContainsString('<dd><span class="aui-mono">woocommerce/order-refund</span></dd>', $out);

        // Approve and Deny post to the existing handlers, each with the nonce the handler checks.
        $this->assertStringContainsString('value="belayline_approve_action"', $out);
        $this->assertStringContainsString('value="belayline_reject_action"', $out);
        $this->assertStringContainsString('name="approval_id" value="apr_1"', $out);
        $this->assertSame(2, substr_count($out, 'name="_wpnonce"'));
        $this->assertMatchesRegularExpression('#aui-btn--primary">Approve</button>#', $out);
        $this->assertMatchesRegularExpression('#aui-btn--secondary">Deny</button>#', $out);
    }

    public function testATierOneCardSaysItNeedsApproval(): void
    {
        $out = $this->render([self::row()], [['event_id' => 'evt_1', 'tier' => 1, 'pack' => 'content', 'ability' => 'x/y']]);

        $this->assertStringContainsString('This needs approval in the Content pack.', $out);
        $this->assertStringNotContainsString('always asks first', $out);
    }

    public function testAnActionWithNoStateCheckSaysSo(): void
    {
        $row = self::row();
        $row['fingerprint_kind'] = 'none';

        $this->assertStringContainsString('nothing compares the current state before it runs', $this->render([$row]));
        $this->assertStringNotContainsString('nothing compares', $this->render([self::row()]));
    }

    public function testNothingWaitingShowsTheEmptyStateWithOneNextAction(): void
    {
        $out = $this->render([]);

        $this->assertStringContainsString('Nothing is waiting', $out);
        $this->assertStringContainsString('page=belayline-packs', $out);
        $this->assertStringNotContainsString('aui-approval', $out);
    }

    public function testAnAgentPlantedLinkInTheSummaryIsStillEscaped(): void
    {
        $row = self::row();
        $row['summary'] = '<a href="javascript:alert(1)">x</a><img src=x onerror=alert(1)>';

        $out = $this->render([$row]);

        $this->assertStringNotContainsString('<a href="javascript:', $out);
        $this->assertStringNotContainsString('<img', $out);
    }

    public function testTheRailHoldsTheNotificationSettingsOnTheExistingHandler(): void
    {
        $db = new wpdb();
        $store = new WpdbApprovalStore($db);
        $page = new PendingActionsPage($store, new Approvals($store));

        ob_start();
        $page->renderRail();
        $out = (string) ob_get_clean();

        $this->assertStringContainsString('value="belayline_save_notifications"', $out);
        $this->assertStringContainsString('name="belayline_notify_email"', $out);
        $this->assertStringContainsString('name="belayline_webhook_url"', $out);
        $this->assertStringContainsString('name="belayline_tab" value="pending"', $out);
    }

    public function testApprovingReturnsToThePendingTab(): void
    {
        $GLOBALS['wpas_test_user_caps'] = ['manage_options' => true];
        $_POST = ['approval_id' => 'apr_1', '_wpnonce' => 'test-nonce'];
        $db = new wpdb();
        $store = new WpdbApprovalStore($db);
        $page = new PendingActionsPage($store, new Approvals($store));

        try {
            $page->reject();
            $this->fail('expected the redirect shim to throw');
        } catch (RedirectException $redirect) {
            $this->assertSame('https://example.test/wp-admin/admin.php?page=belayline-pending', $redirect->location);
        }
    }
}
