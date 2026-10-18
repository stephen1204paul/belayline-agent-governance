<?php

declare(strict_types=1);

namespace Belayline\Plugin\Tests\Admin;

use PHPUnit\Framework\TestCase;
use Belayline\Plugin\Admin\CapabilityPacksPage;
use Belayline\Plugin\Admin\ReasonLabels;
use Belayline\Plugin\Identity\IdentityChain;
use Belayline\Plugin\Support\AdminChangeRecorder;
use Belayline\Plugin\Support\IpAllowlist;
use Belayline\Plugin\Support\PackResolver;
use Belayline\Plugin\Support\PauseSwitch;
use Belayline\Plugin\Support\RequestContext;
use Belayline\Plugin\Support\ShadowMode;
use Belayline\Plugin\Support\UninstallManifest;
use Belayline\Plugin\Tests\Fakes\InMemoryAuditSink;

/** The IP allowlist form on the Packs screen: validation on save, the lock-out warning, the audit row. */
final class CapabilityPacksPageIpAllowlistTest extends TestCase
{
    private InMemoryAuditSink $sink;
    private CapabilityPacksPage $page;

    /** @var array<string, mixed> */
    private array $server;

    protected function setUp(): void
    {
        $this->server = $_SERVER;
        $GLOBALS['wpas_test_options'] = [];
        $GLOBALS['wpas_test_current_user_id'] = 7;
        $GLOBALS['wpas_test_user_caps'] = ['manage_options' => true];
        $_POST = [];
        $_GET = [];
        unset($_SERVER['HTTP_X_FORWARDED_FOR']);
        $_SERVER['REMOTE_ADDR'] = '198.51.100.9';
        RequestContext::reset();

        $this->sink = new InMemoryAuditSink();
        $changes = new AdminChangeRecorder($this->sink);
        $this->page = new CapabilityPacksPage(
            new PackResolver(),
            new IdentityChain(),
            new ShadowMode(),
            $changes,
            new PauseSwitch($changes),
            null,
            new IpAllowlist($changes),
        );
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->server;
        $GLOBALS['wpas_test_options'] = [];
        $GLOBALS['wpas_test_current_user_id'] = 0;
        $GLOBALS['wpas_test_user_caps'] = [];
        $_POST = [];
        $_GET = [];
        RequestContext::reset();
    }

    public function testAValidListIsStoredNormalisedAndAudited(): void
    {
        $result = $this->page->applyIpAllowlist(" 198.51.100.0/24 \n2001:db8::/32\n\n", "10.0.0.0/8\n");

        $this->assertTrue($result['saved']);
        $this->assertFalse($result['excludesCurrentIp']);
        $this->assertSame("198.51.100.0/24\n2001:db8::/32", $GLOBALS['wpas_test_options'][IpAllowlist::OPTION]);
        $this->assertSame('10.0.0.0/8', $GLOBALS['wpas_test_options'][IpAllowlist::PROXIES_OPTION]);

        $this->assertCount(1, $this->sink->records);
        $row = $this->sink->records[0]->toArray();
        $this->assertSame(AdminChangeRecorder::EVENT_IP_ALLOWLIST_CHANGED, $row['reason']);
        $this->assertSame(['198.51.100.0/24', '2001:db8::/32'], $row['input']['allowlist']['to']);
        $this->assertSame(7, $row['input']['by']);
    }

    public function testAMalformedEntryRejectsTheWholeSaveAndChangesNothing(): void
    {
        $this->page->applyIpAllowlist('198.51.100.9', '');
        $this->sink->records = [];

        $result = $this->page->applyIpAllowlist("203.0.113.0/24\n203.0.113.0/40\nfoo", '');

        $this->assertFalse($result['saved']);
        $this->assertSame(['203.0.113.0/40', 'foo'], $result['invalid']);
        $this->assertSame('198.51.100.9', $GLOBALS['wpas_test_options'][IpAllowlist::OPTION]);
        $this->assertSame([], $this->sink->records);
    }

    public function testAMalformedTrustedProxyAlsoRejectsTheSave(): void
    {
        $result = $this->page->applyIpAllowlist('198.51.100.9', 'cloudflare');

        $this->assertFalse($result['saved']);
        $this->assertArrayNotHasKey(IpAllowlist::OPTION, $GLOBALS['wpas_test_options']);
    }

    public function testExcludingTheSavingAdminsAddressWarnsButStillSaves(): void
    {
        $result = $this->page->applyIpAllowlist('203.0.113.0/24', '');

        $this->assertTrue($result['saved']);
        $this->assertTrue($result['excludesCurrentIp']);
        $this->assertSame('203.0.113.0/24', $GLOBALS['wpas_test_options'][IpAllowlist::OPTION]);
    }

    public function testTheWarningUsesTheProposedTrustedProxies(): void
    {
        $_SERVER['REMOTE_ADDR'] = '10.0.0.2';
        $_SERVER['HTTP_X_FORWARDED_FOR'] = '203.0.113.7';

        $this->assertTrue($this->page->applyIpAllowlist('203.0.113.7', '')['excludesCurrentIp'], 'proxy untrusted: the proxy address is judged');
        $this->assertFalse($this->page->applyIpAllowlist('203.0.113.7', '10.0.0.0/8')['excludesCurrentIp']);
    }

    public function testAnEmptyListIsAcceptedAndNeverWarns(): void
    {
        $result = $this->page->applyIpAllowlist('', '');

        $this->assertTrue($result['saved']);
        $this->assertFalse($result['excludesCurrentIp']);
    }

    public function testTheActionRefusesWithoutTheCapability(): void
    {
        $GLOBALS['wpas_test_user_caps'] = ['manage_options' => false];
        $_POST = ['_wpnonce' => 'test-nonce', 'ip_allowlist' => '203.0.113.1'];

        try {
            $this->page->ipAllowlistAction();
            $this->fail('expected wp_die to throw');
        } catch (\RuntimeException) {
            // wp_die shim
        }

        $this->assertArrayNotHasKey(IpAllowlist::OPTION, $GLOBALS['wpas_test_options']);
    }

    public function testTheScreenRendersTheFormWithTheStoredList(): void
    {
        $this->page->applyIpAllowlist('198.51.100.0/24', '');

        ob_start();
        $this->page->renderIpAllowlistCard();
        $html = (string) ob_get_clean();

        $this->assertStringContainsString('name="ip_allowlist"', $html);
        $this->assertStringContainsString('198.51.100.0/24', $html);
        $this->assertStringContainsString('Your current address: 198.51.100.9', $html);
    }

    public function testTheDenyReasonHasAHumanLabelAndBothOptionsUninstall(): void
    {
        $this->assertSame('IP address not allowed', ReasonLabels::label('ip_not_allowed'));
        $this->assertContains(IpAllowlist::OPTION, UninstallManifest::OPTIONS);
        $this->assertContains(IpAllowlist::PROXIES_OPTION, UninstallManifest::OPTIONS);
    }
}
