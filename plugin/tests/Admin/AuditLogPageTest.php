<?php

declare(strict_types=1);

namespace Belayline\Plugin\Tests\Admin;

use PHPUnit\Framework\TestCase;
use Belayline\Plugin\Admin\AuditLogPage;
use Belayline\Plugin\Admin\Map\WhoResolver;
use Belayline\Plugin\Audit\AuditReader;
use wpdb;

/**
 * The audit viewer must render every decision value the chain can hold.
 * PHPUnit runs with failOnWarning, so an undefined-index notice on a row
 * shape the page did not expect fails the test on its own.
 */
final class AuditLogPageTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['wpas_test_user_caps'] = ['manage_options' => true];
    }

    protected function tearDown(): void
    {
        $GLOBALS['wpas_test_user_caps'] = [];
    }

    public function testRendersAnAdminConfigurationRowWithItsOwnBadge(): void
    {
        $db = new wpdb();
        $db->varReturn = 1;
        $db->resultsReturn = [[
            'id' => 1,
            'event_id' => 'evt_1',
            'ts' => '2026-01-01T00:00:00Z',
            'correlation_id' => 'sess_1',
            'pack' => 'admin',
            'ability' => 'belayline_shadow_packs',
            'tier' => null,
            'decision' => 'admin',
            'result' => null,
            'wp_user' => 7,
            'ip' => null,
            'record_json' => '{"reason":"shadow.enabled","input":{"pack":"owner","expires_at":1800000000}}',
            'prev_hash' => str_repeat('0', 64),
            'entry_hash' => str_repeat('0', 64),
        ]];

        ob_start();
        (new AuditLogPage(new AuditReader($db)))->render();
        $out = (string) ob_get_clean();

        $this->assertStringContainsString('>Setting changed</span>', $out, 'admin rows get their own plain-language badge, not the raw value');
        $this->assertStringContainsString('title="admin"', $out, 'the raw decision stays in the tooltip');
        $this->assertStringContainsString('shadow.enabled', $out);
        $this->assertStringContainsString('belayline_shadow_packs', $out);
    }

    public function testShowsTheViaCredentialNextToThePrincipal(): void
    {
        $db = new wpdb();
        $db->varReturn = 1;
        $row = static fn (string $actor): array => [
            'id' => 1, 'event_id' => 'evt_1', 'ts' => '2026-01-01T00:00:00Z', 'correlation_id' => 'sess_1', 'pack' => 'site-admin',
            'ability' => 'core/get-site-info', 'tier' => 0, 'decision' => 'allowed', 'result' => 'success', 'wp_user' => 7, 'ip' => null,
            'record_json' => '{"actor":' . $actor . ',"input":[]}', 'prev_hash' => str_repeat('0', 64), 'entry_hash' => str_repeat('0', 64),
        ];
        $who = new WhoResolver(static fn (): array => ['app:u1' => 'Claude live check'], static fn (int $id): ?string => 'Stephen', static fn (array $ids): array => []);

        $db->resultsReturn = [$row('{"token_id":"role:administrator","wp_user":7,"via":"app:u1"}')];
        ob_start();
        (new AuditLogPage(new AuditReader($db), null, $who))->render();
        $out = (string) ob_get_clean();
        $this->assertStringContainsString('<td>Claude live check via Administrator</td>', $out);

        $db->resultsReturn = [$row('{"token_id":"role:administrator","wp_user":7}')];
        ob_start();
        (new AuditLogPage(new AuditReader($db), null, $who))->render();
        $out = (string) ob_get_clean();
        $this->assertStringContainsString('<td>Administrator</td>', $out);
        $this->assertStringNotContainsString(' via ', $out);

        $db->resultsReturn = [$row('{"token_id":"role:administrator","wp_user":7,"via":"oauth:<b>x"}')];
        ob_start();
        (new AuditLogPage(new AuditReader($db), null, null))->render();
        $out = (string) ob_get_clean();
        $this->assertStringContainsString('oauth:&lt;b&gt;x via role:administrator', $out, 'no resolver: raw tokens, escaped');
    }
}
