<?php

declare(strict_types=1);

namespace Specflux\AgentSafety\Plugin\Tests\Verdict;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Specflux\AgentSafety\Approval\ApprovalBinding;
use Specflux\AgentSafety\Gate\Gate;
use Specflux\AgentSafety\Gate\Outcome;
use Specflux\AgentSafety\Packs\Pack;
use Specflux\AgentSafety\Plugin\Identity\IdentityChain;
use Specflux\AgentSafety\Plugin\Support\AdminChangeRecorder;
use Specflux\AgentSafety\Plugin\Support\ApprovalNotifier;
use Specflux\AgentSafety\Plugin\Support\ArgumentCapGate;
use Specflux\AgentSafety\Plugin\Support\DecisionRecorder;
use Specflux\AgentSafety\Plugin\Support\IpAllowlist;
use Specflux\AgentSafety\Plugin\Support\PauseSwitch;
use Specflux\AgentSafety\Plugin\Support\RateLimitGate;
use Specflux\AgentSafety\Plugin\Support\RequestContext;
use Specflux\AgentSafety\Plugin\Support\ShadowMode;
use Specflux\AgentSafety\Plugin\Support\Tripwires;
use Specflux\AgentSafety\Plugin\Support\WindowCounter;
use Specflux\AgentSafety\Plugin\Tests\Fakes\FakeApprovalStore;
use Specflux\AgentSafety\Plugin\Tests\Fakes\FakeIdentityProvider;
use Specflux\AgentSafety\Plugin\Tests\Fakes\InMemoryAuditSink;
use Specflux\AgentSafety\Plugin\Verdict\Hints;
use Specflux\AgentSafety\Plugin\Verdict\VerdictMode;
use Specflux\AgentSafety\Plugin\Verdict\VerdictPipeline;
use Specflux\AgentSafety\Policy\Tier;
use Specflux\AgentSafety\Policy\TierClassifier;
use Specflux\AgentSafety\Policy\VerbCatalog;

/**
 * The IP allowlist through {@see VerdictPipeline::judge()}: refused remote
 * credentials (`app:` / `wc:`) before any approval or budget, cookie sessions
 * never checked, and the proxy rules that decide which address is judged.
 */
final class VerdictPipelineIpAllowlistTest extends TestCase
{
    private const NOW = 1_800_000_000;

    private InMemoryAuditSink $sink;

    /** @var array<string, mixed> */
    private array $server;

    protected function setUp(): void
    {
        $this->server = $_SERVER;
        $GLOBALS['wpas_test_options'] = [];
        $GLOBALS['wpas_test_transients'] = [];
        \CounterTableWpdb::install();
        $GLOBALS['wpas_test_actions'] = [];
        $GLOBALS['wpas_test_mail'] = [];
        $GLOBALS['wpas_test_time'] = self::NOW;
        remove_all_filters(PauseSwitch::FILTER);
        remove_all_filters(Tripwires::FILTER);
        RequestContext::reset();
        unset($_SERVER['HTTP_X_FORWARDED_FOR']);
        $_SERVER['REMOTE_ADDR'] = '198.51.100.9';

        $this->sink = new InMemoryAuditSink();
    }

    protected function tearDown(): void
    {
        RequestContext::reset();
        $_SERVER = $this->server;
        remove_all_filters(PauseSwitch::FILTER);
        remove_all_filters(Tripwires::FILTER);
        $GLOBALS['wpas_test_options'] = [];
        $GLOBALS['wpas_test_transients'] = [];
        $GLOBALS['wpas_test_actions'] = [];
        $GLOBALS['wpas_test_mail'] = [];
        $GLOBALS['wpas_test_time'] = \time();
    }

    /** @param list<string> $tokens */
    private function pipeline(array $tokens, ?FakeApprovalStore $approvals = null): VerdictPipeline
    {
        $catalog = new VerbCatalog();
        $catalog->register(['demo/read' => Tier::Reversible, 'demo/write' => Tier::SideEffecting, 'demo/delete' => Tier::Irreversible]);
        RequestContext::configure(new IdentityChain([new FakeIdentityProvider(currentTokens: $tokens)]));
        $changes = new AdminChangeRecorder($this->sink);

        return new VerdictPipeline(
            new Gate(new TierClassifier($catalog)),
            new DecisionRecorder($this->sink, $approvals),
            $approvals,
            new RateLimitGate(),
            new ArgumentCapGate(),
            new ShadowMode(),
            null,
            new PauseSwitch($changes),
            new Tripwires(new WindowCounter(), $changes, new ApprovalNotifier()),
        );
    }

    private function allow(string $list, string $proxies = ''): void
    {
        $GLOBALS['wpas_test_options'][IpAllowlist::OPTION] = $list;
        $GLOBALS['wpas_test_options'][IpAllowlist::PROXIES_OPTION] = $proxies;
    }

    private function owner(): Pack
    {
        return new Pack(name: 'owner', allow: ['*']);
    }

    /** @return list<string> */
    private function reasons(): array
    {
        return array_map(static fn ($r): string => (string) $r->toArray()['reason'], $this->sink->records);
    }

    /** @return array<string, array{string}> */
    public static function remoteTokens(): array
    {
        return ['application password' => ['app:abc-123'], 'woocommerce key' => ['wc:key_7']];
    }

    #[DataProvider('remoteTokens')]
    public function testARemoteCredentialFromADisallowedIpIsRefusedAndAudited(string $token): void
    {
        $this->allow("203.0.113.0/24");

        $verdict = $this->pipeline([$token, 'user:5', 'role:editor'])->judge('demo/read', [], $this->owner(), Hints::none(), VerdictMode::Claim);

        $this->assertFalse($verdict->proceeds());
        $this->assertSame(Outcome::Deny, $verdict->decision->outcome);
        $this->assertSame('ip_not_allowed', $verdict->decision->reason);
        $this->assertSame(['ip_not_allowed'], $this->reasons());
        $this->assertSame('denied', $this->sink->records[0]->toArray()['decision']);
        $this->assertNotNull($verdict->error());
    }

    #[DataProvider('remoteTokens')]
    public function testARemoteCredentialFromAnAllowedIpPasses(string $token): void
    {
        $this->allow("203.0.113.0/24\n198.51.100.9");

        $verdict = $this->pipeline([$token])->judge('demo/read', [], $this->owner(), Hints::none(), VerdictMode::Claim);

        $this->assertTrue($verdict->proceeds());
        $this->assertSame([], $this->reasons());
    }

    public function testCidrV4AndV6(): void
    {
        $this->allow('198.51.100.0/24');
        $this->assertTrue($this->pipeline(['app:a'])->judge('demo/read', [], $this->owner(), Hints::none(), VerdictMode::Peek)->proceeds());

        $this->allow('2001:db8:1::/48');
        $_SERVER['REMOTE_ADDR'] = '2001:db8:1:ffff::7';
        $this->assertTrue($this->pipeline(['app:a'])->judge('demo/read', [], $this->owner(), Hints::none(), VerdictMode::Peek)->proceeds());

        $_SERVER['REMOTE_ADDR'] = '2001:db8:2::7';
        $this->assertFalse($this->pipeline(['app:a'])->judge('demo/read', [], $this->owner(), Hints::none(), VerdictMode::Peek)->proceeds());
    }

    public function testACookieSessionIsNeverIpChecked(): void
    {
        $this->allow('203.0.113.1');

        $verdict = $this->pipeline(['user:5', 'role:administrator'])->judge('demo/write', [], $this->owner(), Hints::none(), VerdictMode::Claim);

        $this->assertTrue($verdict->proceeds());
        $this->assertSame([], $this->reasons());
    }

    public function testAnEmptyListAllowsEveryAddress(): void
    {
        foreach (['', "  \n \n"] as $stored) {
            $this->allow($stored);
            $this->assertTrue($this->pipeline(['app:a'])->judge('demo/read', [], $this->owner(), Hints::none(), VerdictMode::Peek)->proceeds());
        }
    }

    public function testNoStoredOptionAtAllAllowsEveryAddress(): void
    {
        $this->assertTrue($this->pipeline(['wc:key_1'])->judge('demo/read', [], $this->owner(), Hints::none(), VerdictMode::Peek)->proceeds());
    }

    public function testForwardedForIsIgnoredWithoutATrustedProxy(): void
    {
        $this->allow('203.0.113.7');
        $_SERVER['HTTP_X_FORWARDED_FOR'] = '203.0.113.7';

        $verdict = $this->pipeline(['app:a'])->judge('demo/read', [], $this->owner(), Hints::none(), VerdictMode::Peek);

        $this->assertSame('ip_not_allowed', $verdict->decision->reason, 'a spoofed header must not satisfy the list');
    }

    public function testForwardedForIsIgnoredWhenRemoteAddrIsNotTheTrustedProxy(): void
    {
        $this->allow('203.0.113.7', '10.0.0.0/8');
        $_SERVER['HTTP_X_FORWARDED_FOR'] = '203.0.113.7';

        $verdict = $this->pipeline(['app:a'])->judge('demo/read', [], $this->owner(), Hints::none(), VerdictMode::Peek);

        $this->assertSame('ip_not_allowed', $verdict->decision->reason);
    }

    public function testForwardedForIsHonouredThroughATrustedProxyUsingTheRightMostUntrustedHop(): void
    {
        $this->allow('203.0.113.7', '10.0.0.0/8');
        $_SERVER['REMOTE_ADDR'] = '10.0.0.2';
        $_SERVER['HTTP_X_FORWARDED_FOR'] = '203.0.113.7, 10.0.0.9';

        $this->assertTrue($this->pipeline(['app:a'])->judge('demo/read', [], $this->owner(), Hints::none(), VerdictMode::Peek)->proceeds());

        // The attacker's own claim sits left of the real hop, so it is never reached.
        $_SERVER['HTTP_X_FORWARDED_FOR'] = '203.0.113.7, 192.0.2.50, 10.0.0.9';
        $verdict = $this->pipeline(['app:a'])->judge('demo/read', [], $this->owner(), Hints::none(), VerdictMode::Peek);
        $this->assertSame('ip_not_allowed', $verdict->decision->reason);
    }

    public function testAMissingRemoteAddrWithAListFailsClosed(): void
    {
        $this->allow('0.0.0.0/0');
        unset($_SERVER['REMOTE_ADDR']);

        $verdict = $this->pipeline(['app:a'])->judge('demo/read', [], $this->owner(), Hints::none(), VerdictMode::Peek);

        $this->assertSame('ip_not_allowed', $verdict->decision->reason);
    }

    public function testAListOfOnlyMalformedLinesStillDeniesRemoteCredentials(): void
    {
        $this->allow("not-an-ip\n10.0.0.0/99");

        $verdict = $this->pipeline(['app:a'])->judge('demo/read', [], $this->owner(), Hints::none(), VerdictMode::Peek);

        $this->assertSame('ip_not_allowed', $verdict->decision->reason);
    }

    public function testARefusedCallNeverClaimsAnApprovalAHumanAlreadyGranted(): void
    {
        $approvals = new FakeApprovalStore();
        $args = ['id' => 9];
        $id = $approvals->seedApproved('demo/delete', ApprovalBinding::hash('demo/delete', $args), 'app:a');
        $this->allow('203.0.113.1');

        $verdict = $this->pipeline(['app:a'], $approvals)->judge('demo/delete', $args, $this->owner(), Hints::none(), VerdictMode::Claim);

        $this->assertSame('ip_not_allowed', $verdict->decision->reason);
        $this->assertNull($verdict->reservedApprovalId);
        $this->assertNotNull($id);
    }

    public function testARefusedCallConsumesNoBudgetAndIsNotCountedTowardsLockout(): void
    {
        $this->allow('203.0.113.1');
        $limited = new Pack(name: 'limited', allow: ['*'], limits: ['calls_per_minute' => 1]);

        for ($i = 0; $i < 12; $i++) {
            $this->pipeline(['app:a'])->judge('demo/write', ['n' => $i], $limited, Hints::none(), VerdictMode::Claim);
        }
        $this->assertSame(array_fill(0, 12, 'ip_not_allowed'), $this->reasons(), 'never denial_lockout or a rate limit');

        $this->allow('');
        $this->assertTrue($this->pipeline(['app:a'])->judge('demo/write', ['n' => 99], $limited, Hints::none(), VerdictMode::Claim)->proceeds());
    }

    public function testShadowModeDoesNotLoosenTheRefusal(): void
    {
        $GLOBALS['wpas_test_options'][ShadowMode::OPTION] = ['owner' => self::NOW + 3600];
        $this->allow('203.0.113.1');

        $verdict = $this->pipeline(['app:a'])->judge('demo/read', [], $this->owner(), Hints::none(), VerdictMode::Claim);

        $this->assertFalse($verdict->proceeds());
        $this->assertSame('ip_not_allowed', $verdict->decision->reason);
    }
}
