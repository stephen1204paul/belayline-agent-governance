<?php

declare(strict_types=1);

namespace Belayline\Plugin\Tests\Support;

use Belayline\Packs\Pack;
use Belayline\Plugin\Identity\IdentityChain;
use Belayline\Plugin\Support\PackResolver;
use Belayline\Plugin\Support\RequestContext;
use Belayline\Plugin\Tests\Fakes\FakeIdentityProvider;
use PHPUnit\Framework\TestCase;

/**
 * The audit actor names the principal (`token_id`, the credential whose binding
 * decided the pack) and, when a different agent credential is present, that
 * credential as `via` so an unbound AI app is never anonymous in the log.
 */
final class RequestContextViaTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['wpas_test_options'] = [];
    }

    protected function tearDown(): void
    {
        RequestContext::reset();
        $GLOBALS['wpas_test_options'] = [];
    }

    /**
     * @param list<string>          $tokens
     * @param array<string, string> $bindings
     */
    private function configure(array $tokens, array $bindings): void
    {
        RequestContext::configure(new IdentityChain([new FakeIdentityProvider(currentTokens: $tokens)]));
        $GLOBALS['wpas_test_options'][PackResolver::BINDINGS_OPTION] = $bindings;
        $resolver = new PackResolver([new Pack(name: 'site-admin', allow: ['core/*'])]);
        RequestContext::configurePrincipalResolver([$resolver, 'principal']);
    }

    public function testBoundOauthTokenWinsPrincipalAndAddsNoVia(): void
    {
        $this->configure(['oauth:d7acc', 'role:administrator'], ['oauth:d7acc' => 'site-admin', 'role:administrator' => 'site-admin']);

        $actor = RequestContext::actor();

        $this->assertSame('oauth:d7acc', $actor['token_id']);
        $this->assertArrayNotHasKey('via', $actor);
    }

    public function testUnboundOauthTokenIsNamedViaWhenARoleBindingWins(): void
    {
        $this->configure(['oauth:d7acc', 'role:administrator'], ['role:administrator' => 'site-admin']);

        $actor = RequestContext::actor();

        $this->assertSame('role:administrator', $actor['token_id']);
        $this->assertSame('oauth:d7acc', $actor['via']);
        $this->assertSame('via', array_key_last($actor), 'via is the last key so older keys keep their order');
    }

    public function testAnOrdinaryLoggedInPersonHasNoVia(): void
    {
        $this->configure(['user:5', 'role:administrator'], ['role:administrator' => 'site-admin']);

        $actor = RequestContext::actor();

        $this->assertSame('role:administrator', $actor['token_id']);
        $this->assertArrayNotHasKey('via', $actor);
    }

    public function testFirstAgentCredentialIsTheOneNamed(): void
    {
        $this->configure(['app:one', 'oauth:two', 'role:administrator'], ['role:administrator' => 'site-admin']);

        $this->assertSame('app:one', RequestContext::actor()['via']);
    }

    public function testNoTokensMeansNoVia(): void
    {
        $this->configure([], []);

        $this->assertArrayNotHasKey('via', RequestContext::actor());
    }
}
