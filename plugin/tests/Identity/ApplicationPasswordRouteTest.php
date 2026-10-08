<?php

declare(strict_types=1);

namespace Specflux\AgentSafety\Plugin\Tests\Identity;

use PHPUnit\Framework\TestCase;
use Specflux\AgentSafety\Gate\Gate;
use Specflux\AgentSafety\Packs\Pack;
use Specflux\AgentSafety\Plugin\Hooks\AbilityPermissionGate;
use Specflux\AgentSafety\Plugin\Identity\ApplicationPasswordIdentity;
use Specflux\AgentSafety\Plugin\Identity\IdentityChain;
use Specflux\AgentSafety\Plugin\Support\DecisionRecorder;
use Specflux\AgentSafety\Plugin\Support\PackResolver;
use Specflux\AgentSafety\Plugin\Support\RequestContext;
use Specflux\AgentSafety\Plugin\Verdict\VerdictPipeline;
use Specflux\AgentSafety\Policy\Tier;
use Specflux\AgentSafety\Policy\TierClassifier;
use Specflux\AgentSafety\Policy\VerbCatalog;
use WP_Error;

/**
 * A connector such as SenroFlux's `/wp-json/senroflux/mcp` authenticates each
 * request with a WordPress application password. The gate wraps the ABILITY's
 * permission callback, so which REST route carried the request is irrelevant:
 * the pack follows the application-password UUID the request authenticated
 * with, and an UNBOUND password gets the default pack, which allows nothing.
 * Driven through the real {@see ApplicationPasswordIdentity}.
 */
final class ApplicationPasswordRouteTest extends TestCase
{
    private const ABILITY = 'senroflux/content-update';
    private const UUID = '7c1b9f0e-1111-4222-8333-444455556666';

    protected function setUp(): void
    {
        RequestContext::reset();
        $GLOBALS['wpas_test_options'] = [];
        $GLOBALS['wpas_test_transients'] = [];
        $GLOBALS['wpas_test_app_password'] = null;
        \CounterTableWpdb::install();
        RequestContext::configure(new IdentityChain([new ApplicationPasswordIdentity()]));
    }

    protected function tearDown(): void
    {
        RequestContext::reset();
        $GLOBALS['wpas_test_options'] = [];
        $GLOBALS['wpas_test_transients'] = [];
        $GLOBALS['wpas_test_app_password'] = null;
    }

    private function permissionCallback(): callable
    {
        $catalog = new VerbCatalog();
        $catalog->register([self::ABILITY => Tier::Reversible]);
        $gate = new AbilityPermissionGate(
            new VerdictPipeline(new Gate(new TierClassifier($catalog)), new DecisionRecorder()),
            new PackResolver([new Pack(name: 'senroflux-content', allow: ['senroflux/*'])]),
            null,
            ['senroflux/'],
        );

        return $gate->wrap(['permission_callback' => static fn () => true], self::ABILITY)['permission_callback'];
    }

    public function testAPackBoundToAnApplicationPasswordAppliesToACallAuthenticatedWithIt(): void
    {
        $GLOBALS['wpas_test_options'][PackResolver::BINDINGS_OPTION] = ['app:' . self::UUID => 'senroflux-content'];
        $GLOBALS['wpas_test_app_password'] = self::UUID;

        $this->assertSame(['app:' . self::UUID], RequestContext::currentTokens());
        $this->assertTrue(($this->permissionCallback())([]));
    }

    public function testAnUnboundApplicationPasswordGetsTheFailClosedDefault(): void
    {
        $GLOBALS['wpas_test_options'][PackResolver::BINDINGS_OPTION] = ['app:' . self::UUID => 'senroflux-content'];
        $GLOBALS['wpas_test_app_password'] = 'aaaaaaaa-0000-4000-8000-bbbbbbbbbbbb';

        $result = ($this->permissionCallback())([]);

        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame('default-agent', (new PackResolver())->resolve()->name);
    }

    public function testARequestWithNoApplicationPasswordIsNotCoveredByTheBinding(): void
    {
        $GLOBALS['wpas_test_options'][PackResolver::BINDINGS_OPTION] = ['app:' . self::UUID => 'senroflux-content'];

        $this->assertInstanceOf(WP_Error::class, ($this->permissionCallback())([]));
    }
}
