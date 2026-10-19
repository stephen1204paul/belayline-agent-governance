<?php

declare(strict_types=1);

namespace Belayline\Plugin\Tests\Changes;

use PHPUnit\Framework\TestCase;
use Belayline\Changes\UndoResult;
use Belayline\Changes\UndoScope;
use Belayline\Plugin\Changes\ChangeCapture;
use Belayline\Plugin\Changes\UndoService;
use Belayline\Plugin\Identity\ApplicationPasswordIdentity;
use Belayline\Plugin\Identity\ExternalIdentitySlot;
use Belayline\Plugin\Identity\IdentityChain;
use Belayline\Plugin\Identity\UserRoleIdentity;
use Belayline\Plugin\Support\PackResolver;
use Belayline\Plugin\Support\RequestContext;
use Belayline\Plugin\Tests\Fakes\FakeIdentityProvider;
use Belayline\Plugin\Tests\Fakes\InMemoryChangeLedger;
use Belayline\Policy\Tier;
use Belayline\Policy\TierClassifier;
use Belayline\Policy\VerbCatalog;

/**
 * Undo's person-vs-agent gate against the production identity wiring (the real
 * identity chain and the real principal resolver), not a stubbed principal.
 * A signed-in human always has `user:`/`role:` tokens, so "has a token" must not
 * mean "is an agent".
 */
final class UndoRealIdentityTest extends TestCase
{
    private UndoService $undo;
    private string $event;

    protected function setUp(): void
    {
        RequestContext::reset();
        ChangeCapture::configure(null);
        $GLOBALS['wpas_test_posts'] = [7 => (object) [
            'ID' => 7, 'post_title' => 'A', 'post_type' => 'post', 'post_status' => 'publish',
            'post_password' => '', 'post_modified' => '2026-01-01 00:00:00',
        ]];
        $GLOBALS['wpas_test_terms'] = [];
        $GLOBALS['wpas_test_meta'] = [];
        $GLOBALS['wpas_test_options'] = [];
        $GLOBALS['wpas_test_added_actions'] = [];
        $GLOBALS['wpas_test_filters'] = [];
        $GLOBALS['wpas_test_app_password'] = null;
        $GLOBALS['wpas_test_current_user_id'] = 1;
        $GLOBALS['wpas_test_current_user_roles'] = ['administrator'];
        $GLOBALS['wpas_test_user_caps_by_id'] = [1 => ['manage_options' => true]];

        $catalog = new VerbCatalog();
        $catalog->register(['t/write' => Tier::SideEffecting]);
        $ledger = new InMemoryChangeLedger();
        $resolver = new PackResolver();
        $capture = new ChangeCapture(new TierClassifier($catalog), $resolver, $ledger);
        $this->undo = new UndoService($ledger, $capture->adapters(), $capture);

        // Production wiring: identity chain + the resolver's principal().
        $this->wire(new IdentityChain([new ApplicationPasswordIdentity(), new UserRoleIdentity()]), $resolver);

        $capture->run('t/write', [], static fn () => wp_update_post(wp_slash(['ID' => 7, 'post_title' => 'B'])));
        $this->event = (string) $capture->takeSettled('t/write')['event_id'];
    }

    protected function tearDown(): void
    {
        RequestContext::reset();
        $GLOBALS['wpas_test_app_password'] = null;
        $GLOBALS['wpas_test_current_user_roles'] = [];
        $GLOBALS['wpas_test_user_caps_by_id'] = [];
        $GLOBALS['wpas_test_current_user_id'] = 0;
    }

    private function wire(IdentityChain $chain, ?PackResolver $resolver = null): void
    {
        RequestContext::configure($chain);
        RequestContext::configurePrincipalResolver([$resolver ?? new PackResolver(), 'principal']);
    }

    private function undoNow(): UndoResult
    {
        return $this->undo->undo(UndoScope::event($this->event), 1);
    }

    public function testASignedInHumanOnACookieSessionCanUndo(): void
    {
        $this->assertSame(['user:1', 'role:administrator'], RequestContext::currentTokens());
        $this->assertSame('user:1', RequestContext::tokenId(), 'the human has a principal, so tokenId() cannot mean agent');

        $this->assertSame(UndoResult::COMPLETE, $this->undoNow()->status);
        $this->assertSame('A', $GLOBALS['wpas_test_posts'][7]->post_title);
    }

    public function testAHumanWhoseRoleIsBoundToAPackCanStillUndo(): void
    {
        $GLOBALS['wpas_test_options'][PackResolver::BINDINGS_OPTION] = ['role:administrator' => 'owner'];
        $this->wire(new IdentityChain([new ApplicationPasswordIdentity(), new UserRoleIdentity()]));

        $this->assertSame('role:administrator', RequestContext::tokenId());
        $this->assertSame(UndoResult::COMPLETE, $this->undoNow()->status);
    }

    public function testTheSameUserOnAnApplicationPasswordIsAnAgent(): void
    {
        $GLOBALS['wpas_test_app_password'] = 'uuid-1';
        RequestContext::forgetPrincipal();

        $this->assertSame(['app:uuid-1', 'user:1', 'role:administrator'], RequestContext::currentTokens());
        $result = $this->undoNow();
        $this->assertSame(UndoResult::FORBIDDEN, $result->status);
        $this->assertSame(UndoResult::REASON_AGENT_CONTEXT, $result->reason);
        $this->assertSame('B', $GLOBALS['wpas_test_posts'][7]->post_title);
    }

    public function testAnApplicationPasswordBoundToAPackIsStillAnAgent(): void
    {
        $GLOBALS['wpas_test_app_password'] = 'uuid-1';
        $GLOBALS['wpas_test_options'][PackResolver::BINDINGS_OPTION] = ['role:administrator' => 'owner'];
        $this->wire(new IdentityChain([new ApplicationPasswordIdentity(), new UserRoleIdentity()]));

        $this->assertSame(UndoResult::REASON_AGENT_CONTEXT, $this->undoNow()->reason);
    }

    public function testASignedInPersonOnAnOauthBearerIsAnAgentAndCannotUndo(): void
    {
        add_filter(ExternalIdentitySlot::FILTER, static fn (array $l): array => [...$l, new FakeIdentityProvider(['oauth:g1'])]);
        $this->wire(new IdentityChain([new ApplicationPasswordIdentity(), new ExternalIdentitySlot(), new UserRoleIdentity()]));

        $this->assertSame(['oauth:g1', 'user:1', 'role:administrator'], RequestContext::currentTokens());
        $result = $this->undoNow();
        $this->assertSame(UndoResult::FORBIDDEN, $result->status);
        $this->assertSame(UndoResult::REASON_AGENT_CONTEXT, $result->reason);
        $this->assertSame('B', $GLOBALS['wpas_test_posts'][7]->post_title);
    }

    /** @return array<string, array{list<string>}> */
    public static function nonPersonTokens(): array
    {
        return [
            'woocommerce key' => [['wc:key_7', 'user:1']],
            'unknown prefix' => [['mystery:abc', 'user:1', 'role:administrator']],
            'integration token' => [['acme:9']],
            'bare unprefixed' => [['app_pw_1']],
            'empty-ish prefix lookalike' => [['users:1']],
        ];
    }

    /** @param list<string> $tokens */
    #[\PHPUnit\Framework\Attributes\DataProvider('nonPersonTokens')]
    public function testAnyNonPersonTokenIsAnAgent(array $tokens): void
    {
        $this->wire(new IdentityChain([new FakeIdentityProvider(currentTokens: $tokens)]));

        $this->assertSame(UndoResult::REASON_AGENT_CONTEXT, $this->undoNow()->reason);
        $this->assertSame('B', $GLOBALS['wpas_test_posts'][7]->post_title);
    }
}
