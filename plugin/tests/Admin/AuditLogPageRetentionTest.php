<?php

declare(strict_types=1);

namespace Belayline\Plugin\Tests\Admin;

use Belayline\Plugin\Admin\AuditLogPage;
use Belayline\Plugin\Audit\AuditReader;
use Belayline\Plugin\Support\AuditRetention;
use Belayline\Plugin\Tests\Fakes\SqliteWpdb;
use PHPUnit\Framework\TestCase;

/** Capability, nonce and input handling for the audit retention setting and the JSON Lines export. */
final class AuditLogPageRetentionTest extends TestCase
{
    private AuditLogPage $page;

    protected function setUp(): void
    {
        $GLOBALS['wpas_test_user_caps'] = ['manage_options' => true];
        $GLOBALS['wpas_test_options'] = [];
        $_POST = [];
        $this->page = new AuditLogPage(new AuditReader(new SqliteWpdb()));
    }

    protected function tearDown(): void
    {
        $GLOBALS['wpas_test_user_caps'] = [];
        $_POST = [];
    }

    public function testExportRefusesWithoutManageOptions(): void
    {
        $GLOBALS['wpas_test_user_caps'] = [];
        $_POST = ['_wpnonce' => 'test-nonce'];

        $this->expectExceptionMessage('wp_die');
        $this->page->exportJsonl();
    }

    public function testExportRefusesWithoutAValidNonce(): void
    {
        $_POST = ['from' => '2026-01-01'];

        $this->expectExceptionMessage('wp_die: The link you followed has expired.');
        $this->page->exportJsonl();
    }

    public function testExportRefusesAnImpossibleDateBeforeSendingAnything(): void
    {
        $_POST = ['_wpnonce' => 'test-nonce', 'from' => '2026-02-30'];

        $this->expectExceptionMessage('wp_die');
        $this->page->exportJsonl();
    }

    public function testRetentionSaveRefusesWithoutCapabilityOrNonce(): void
    {
        $_POST = ['belayline_audit_retention_days' => '30'];
        try {
            $this->page->applyRetentionSubmission();
            $this->fail('accepted a save with no nonce');
        } catch (\RuntimeException) {
            $this->assertArrayNotHasKey(AuditRetention::OPTION, $GLOBALS['wpas_test_options']);
        }

        $GLOBALS['wpas_test_user_caps'] = [];
        $_POST['_wpnonce'] = 'test-nonce';
        try {
            $this->page->applyRetentionSubmission();
            $this->fail('accepted a save without manage_options');
        } catch (\RuntimeException) {
            $this->assertArrayNotHasKey(AuditRetention::OPTION, $GLOBALS['wpas_test_options']);
        }
    }

    public function testRetentionSaveStoresAnAbsintAndTreatsGarbageAsForever(): void
    {
        $_POST = ['_wpnonce' => 'test-nonce', 'belayline_audit_retention_days' => '-45'];
        $this->page->applyRetentionSubmission();
        $this->assertSame(45, $GLOBALS['wpas_test_options'][AuditRetention::OPTION]);

        $_POST['belayline_audit_retention_days'] = 'lots';
        $this->page->applyRetentionSubmission();
        $this->assertSame(0, $GLOBALS['wpas_test_options'][AuditRetention::OPTION]);
    }

    public function testScreenOffersTheRetentionFieldAndTheJsonLinesDownload(): void
    {
        update_option(AuditRetention::OPTION, 90);

        ob_start();
        $this->page->render();
        $out = (string) ob_get_clean();

        $this->assertStringContainsString('name="belayline_audit_retention_days"', $out);
        $this->assertStringContainsString('value="90"', $out);
        $this->assertStringContainsString('Download JSON Lines', $out);
        $this->assertStringContainsString('name="from"', $out);
        $this->assertStringContainsString('value="belayline_export_audit_jsonl"', $out);
        $this->assertStringContainsString('value="belayline_save_audit_retention"', $out);
    }

    public function testScreenReportsThePrunedCountWhenACheckpointExists(): void
    {
        $db = new SqliteWpdb();
        $db->insert('wp_belayline_audit_checkpoint', ['created_ts' => '2026-03-01 00:00:00', 'pruned_through_id' => 9, 'pruned_through_entry_hash' => str_repeat('b', 64), 'pruned_count' => 9]);

        ob_start();
        (new AuditLogPage(new AuditReader($db)))->render();
        $out = (string) ob_get_clean();

        $this->assertStringContainsString('9 older events were pruned', $out);
    }
}
