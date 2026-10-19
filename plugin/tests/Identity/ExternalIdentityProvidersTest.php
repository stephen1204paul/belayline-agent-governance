<?php

declare(strict_types=1);

namespace Belayline\Plugin\Tests\Identity;

use PHPUnit\Framework\TestCase;
use Belayline\Plugin\Admin\CapabilityPacksPage;
use Belayline\Plugin\Identity\ApplicationPasswordIdentity;
use Belayline\Plugin\Identity\ExternalIdentitySlot;
use Belayline\Plugin\Identity\IdentityChain;
use Belayline\Plugin\Identity\UserRoleIdentity;
use Belayline\Plugin\Support\AdminChangeRecorder;
use Belayline\Plugin\Support\PackResolver;
use Belayline\Plugin\Support\RequestContext;
use Belayline\Plugin\Support\ShadowMode;
use Belayline\Plugin\Tests\Fakes\FakeIdentityProvider;
use Belayline\Plugin\Tests\Fakes\InMemoryAuditSink;

/** The public `belayline_identity_providers` filter and its read-time guard. */
final class ExternalIdentityProvidersTest extends TestCase
{
    protected function setUp(): void
    {
        RequestContext::reset();
        remove_all_filters(ExternalIdentitySlot::FILTER);
        $GLOBALS['wpas_test_options'] = [];
        $GLOBALS['wpas_test_app_password'] = null;
        $GLOBALS['wpas_test_current_user_id'] = 7;
        $GLOBALS['wpas_test_current_user_roles'] = ['editor'];
        $GLOBALS['wpas_test_user_caps'] = ['manage_options' => true];
    }

    protected function tearDown(): void
    {
        RequestContext::reset();
        remove_all_filters(ExternalIdentitySlot::FILTER);
        $GLOBALS['wpas_test_options'] = [];
        $GLOBALS['wpas_test_app_password'] = null;
        $GLOBALS['wpas_test_current_user_id'] = 0;
        $GLOBALS['wpas_test_current_user_roles'] = [];
        $GLOBALS['wpas_test_user_caps'] = [];
    }

    /** @param list<mixed> $extra */
    private function chain(array $extra): IdentityChain
    {
        add_filter(ExternalIdentitySlot::FILTER, static fn (array $list): array => [...$list, ...$extra]);

        return new IdentityChain([new ApplicationPasswordIdentity(), new ExternalIdentitySlot(), new UserRoleIdentity()]);
    }

    public function testAContributedTokenSitsAfterAppPasswordsAndBeforeThePerson(): void
    {
        $GLOBALS['wpas_test_app_password'] = 'uuid-1';
        RequestContext::configure($this->chain([new FakeIdentityProvider(['oauth:g1'])]));

        $this->assertSame(['app:uuid-1', 'oauth:g1', 'user:7', 'role:editor'], RequestContext::currentTokens());
    }

    public function testABoundContributedTokenWinsThePrincipal(): void
    {
        $GLOBALS['wpas_test_options'][PackResolver::BINDINGS_OPTION] = ['oauth:g1' => 'owner', 'user:7' => 'default-agent'];
        RequestContext::configure($this->chain([new FakeIdentityProvider(['oauth:g1'])]));

        $this->assertSame('oauth:g1', (new PackResolver())->principal());
    }

    public function testAnUnboundContributedTokenFallsBackToThePersonsBinding(): void
    {
        $GLOBALS['wpas_test_options'][PackResolver::BINDINGS_OPTION] = ['role:editor' => 'owner'];
        RequestContext::configure($this->chain([new FakeIdentityProvider(['oauth:g1'])]));

        $this->assertSame('role:editor', (new PackResolver())->principal());
    }

    public function testReservedAndUnnamespacedTokensAreDroppedAndTheRestKept(): void
    {
        RequestContext::configure($this->chain([
            new FakeIdentityProvider(['user:7', 'app:x', 'role:admin', 'wc:k', 'bare', 'Oauth:Up', 'oauth:ok'], ['user:9' => 'Nope', 'oauth:ok' => 'Ok']),
        ]));

        $this->assertSame(['oauth:ok', 'user:7', 'role:editor'], RequestContext::currentTokens());
    }

    public function testEntriesThatAreNotIdentityProvidersAreIgnored(): void
    {
        RequestContext::configure($this->chain(['oauth:g1', new \stdClass(), ['x'], null, new FakeIdentityProvider(['oauth:g2'])]));

        $this->assertSame(['oauth:g2', 'user:7', 'role:editor'], RequestContext::currentTokens());
    }

    public function testALateRegisteredFilterIsStillHonoured(): void
    {
        $chain = new IdentityChain([new ApplicationPasswordIdentity(), new ExternalIdentitySlot(), new UserRoleIdentity()]);
        add_filter(ExternalIdentitySlot::FILTER, static fn (array $l): array => [...$l, new FakeIdentityProvider(['oauth:late'])]);
        RequestContext::configure($chain);

        $this->assertSame('oauth:late', RequestContext::currentTokens()[0]);
    }

    public function testAnOauthCallIsAnAgentContext(): void
    {
        RequestContext::configure($this->chain([]));
        $this->assertFalse(RequestContext::isAgentContext());

        RequestContext::reset();
        RequestContext::configure($this->chain([new FakeIdentityProvider(['oauth:g1'])]));
        $this->assertTrue(RequestContext::isAgentContext());
    }

    public function testThePacksPageListsTheContributedBindableTokensOnly(): void
    {
        $chain = $this->chain([
            new FakeIdentityProvider([], ['oauth:g1' => 'Claude', 'user:99' => 'Spoof'], 'AI apps'),
        ]);
        $page = new CapabilityPacksPage(new PackResolver(), $chain, new ShadowMode(), new AdminChangeRecorder(new InMemoryAuditSink()));

        ob_start();
        $page->renderBindingsCard();
        $out = (string) ob_get_clean();

        $this->assertStringContainsString('AI apps', $out);
        $this->assertStringContainsString('Claude', $out);
        $this->assertStringContainsString('oauth:g1', $out);
        $this->assertStringNotContainsString('user:99', $out);
    }
}
