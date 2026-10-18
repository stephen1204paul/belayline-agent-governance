<?php

declare(strict_types=1);

namespace Belayline\Plugin\Tests\Hooks;

use PHPUnit\Framework\TestCase;
use Belayline\Approval\ApprovalBinding;
use Belayline\Gate\Gate;
use Belayline\Packs\Pack;
use Belayline\Plugin\Hooks\PreToolCallGate;
use Belayline\Plugin\Identity\IdentityChain;
use Belayline\Plugin\Integrations\Woo\VerbMapper;
use Belayline\Plugin\Support\DecisionRecorder;
use Belayline\Plugin\Support\PackResolver;
use Belayline\Plugin\Support\RequestContext;
use Belayline\Plugin\Tests\Fakes\FakeApprovalStore;
use Belayline\Plugin\Tests\Fakes\FakeIdentityProvider;
use Belayline\Plugin\Tests\Fakes\FakeMcpTool;
use Belayline\Plugin\Tests\Fakes\InMemoryAuditSink;
use Belayline\Plugin\Verdict\VerdictPipeline;
use Belayline\Policy\Tier;
use Belayline\Policy\TierClassifier;
use Belayline\Policy\VerbCatalog;
use WP_Error;

/**
 * {@see PreToolCallGate} is the PEEK-mode ADAPTER of the shared
 * {@see VerdictPipeline}, so this file only tests the adapting: the
 * `mcp_adapter_pre_tool_call` filter contract (args through, or a WP_Error
 * back), that the tool name is mapped to a verb and the tool's annotations are
 * read into Hints before the pipeline is asked, and that it judges in Peek
 * mode so the permission_callback stays the sole owner of reserve→finalize.
 *
 * Every gating BEHAVIOUR the verdict comes from — rate caps, argument caps,
 * shadow mode, the approval flow, what a hint does to a decision — is proven
 * once, in both modes, in
 * {@see \Belayline\Plugin\Tests\Verdict\VerdictPipelineTest}
 * (and hint PARSING in
 * {@see \Belayline\Plugin\Tests\Verdict\HintsTest}). Re-asserting
 * it here would only re-prove the pipeline through a keyhole.
 *
 * "demo-write" (-> verb "demo/write") is a Tier 1 verb an
 * unrestricted-but-approval-gated pack allows outright, which is exactly the
 * case a destructiveHint must be able to escalate.
 */
final class PreToolCallGateTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['wpas_test_options'] = [];
        $GLOBALS['wpas_test_transients'] = [];
        \CounterTableWpdb::install();
    }

    protected function tearDown(): void
    {
        RequestContext::reset();
        $GLOBALS['wpas_test_options'] = [];
        $GLOBALS['wpas_test_transients'] = [];
    }

    /**
     * @param array<string, Tier> $verbToTier
     */
    private function gateFor(array $verbToTier, Pack $pack, ?DecisionRecorder $recorder = null, ?FakeApprovalStore $approvals = null): PreToolCallGate
    {
        $catalog = new VerbCatalog();
        $catalog->register($verbToTier);

        RequestContext::configure(new IdentityChain([
            new FakeIdentityProvider(currentTokens: ['test:token']),
        ]));
        $GLOBALS['wpas_test_options'][PackResolver::BINDINGS_OPTION] = ['test:token' => $pack->name];

        return new PreToolCallGate(
            new VerdictPipeline(new Gate(new TierClassifier($catalog)), $recorder ?? new DecisionRecorder(), $approvals),
            new VerbMapper(),
            new PackResolver([$pack]),
            ['demo/'],
        );
    }

    private function approvalGatedPack(): Pack
    {
        return new Pack(name: 'support', allow: ['demo/*'], approvalByClass: ['tier2' => true]);
    }

    public function testAnAllowedCallReturnsTheArgsArrayUnchanged(): void
    {
        // The filter's contract: returning the args (not true) is what lets the
        // tool call proceed, and mutating them here would rewrite the call.
        $gate = $this->gateFor(['demo/write' => Tier::SideEffecting], new Pack(name: 'owner', allow: ['*']));

        $this->assertSame(['id' => 1], $gate->handle(['id' => 1], 'demo-write'));
    }

    public function testAGovernedCallFailsClosedWhenTheSchemaInstallFailed(): void
    {
        $gate = $this->gateFor(['demo/write' => Tier::SideEffecting], new Pack(name: 'owner', allow: ['*']));
        $db = new \Belayline\Plugin\Tests\Fakes\FailingInstallWpdb();
        \Belayline\Plugin\Support\Schema::ensureCurrent($db);

        try {
            $result = $gate->handle(['id' => 1], 'demo-write');
        } finally {
            \Belayline\Plugin\Support\Schema::resetInstallFailure();
        }

        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame('belayline_not_installed', $result->get_error_code());
    }

    public function testABlockedCallReturnsTheVerdictsDenyError(): void
    {
        $gate = $this->gateFor(['demo/write' => Tier::SideEffecting], new Pack(name: 'walled', allow: []));

        $result = $gate->handle(['id' => 1], 'demo-write');

        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame('belayline_denied', $result->get_error_code());
        $this->assertStringContainsString('not_in_pack', $result->get_error_message());
        $this->assertSame(403, $result->get_error_data()['status']);
    }

    public function testAParkedCallReturnsTheApprovalErrorCarryingTheApprovalId(): void
    {
        // Returning the WP_Error from THIS filter is the whole point of the
        // seam: WP_Ability::execute() would otherwise mask it to a generic
        // ability_invalid_permissions and the agent would never see the id.
        $approvals = new FakeApprovalStore();
        $gate = $this->gateFor(
            ['demo/refund' => Tier::Irreversible],
            $this->approvalGatedPack(),
            new DecisionRecorder(new InMemoryAuditSink(), $approvals),
            $approvals,
        );

        $result = $gate->handle(['amount' => 5], 'demo-refund');

        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame('approval_required', $result->get_error_code());
        $this->assertCount(1, $approvals->requestCalls);
        $this->assertSame(array_key_first($approvals->rows), $result->get_error_data()['approval_id']);
    }

    public function testTheToolsDestructiveAnnotationReachesThePipeline(): void
    {
        // Proves Hints::fromMcpTool() is actually wired into the judge() call:
        // the same verb and pack allow the call outright without the tool object.
        $gate = $this->gateFor(['demo/write' => Tier::SideEffecting], $this->approvalGatedPack());

        $this->assertSame([], $gate->handle([], 'demo-write'));

        $result = $gate->handle([], 'demo-write', FakeMcpTool::withHints(destructiveHint: true));

        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame('approval_required', $result->get_error_code());
        $this->assertSame(Tier::Irreversible->value, $result->get_error_data()['tier'] ?? null);
    }

    public function testAnAlreadyApprovedRetryProceedsWithoutReservingTheGrant(): void
    {
        // Proves the seam judges in Peek mode: the retry is admitted, but the
        // grant is left `approved` for the permission_callback (which runs on
        // every adapter version) to reserve and finalize.
        $approvals = new FakeApprovalStore();
        $args = ['amount' => 5];
        $id = $approvals->seedApproved('demo/refund', ApprovalBinding::hash('demo/refund', $args), 'test:token');
        $gate = $this->gateFor(
            ['demo/refund' => Tier::Irreversible],
            $this->approvalGatedPack(),
            new DecisionRecorder(new InMemoryAuditSink(), $approvals),
            $approvals,
        );

        $result = $gate->handle($args, 'demo-refund');

        $this->assertSame($args, $result);
        $this->assertSame('approved', $approvals->rows[$id]['status']);
    }

    public function testAToolOutsideTheGovernedNamespacesPassesThrough(): void
    {
        // mcp-adapter's own execute-ability tool maps to `mcp/adapter-execute-ability`,
        // which no integration governs. The ability it runs is judged by the
        // permission_callback wrap during the adapter's permission check, which
        // runs before this filter; denying the wrapper here as unknown_verb
        // blocked every allowed call made through it.
        $gate = $this->gateFor(['demo/write' => Tier::SideEffecting], new Pack(name: 'walled', allow: []));

        $args = ['ability_name' => 'belayline/check-approval', 'parameters' => ['approval_id' => 'apr_x']];

        $this->assertSame($args, $gate->handle($args, 'mcp-adapter-execute-ability'));
    }

    public function testAnUncataloguedToolInsideAGovernedNamespaceStillFailsClosed(): void
    {
        $gate = $this->gateFor(['demo/write' => Tier::SideEffecting], new Pack(name: 'owner', allow: ['*']));

        $result = $gate->handle([], 'demo-unlisted');

        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertStringContainsString('unknown_verb', $result->get_error_message());
    }
}
