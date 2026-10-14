<?php

declare(strict_types=1);

namespace Belayline\Plugin\Tests\Verdict;

use PHPUnit\Framework\TestCase;
use Belayline\Gate\Outcome;
use Belayline\Packs\Pack;
use Belayline\Plugin\Identity\IdentityChain;
use Belayline\Plugin\Support\AdminChangeRecorder;
use Belayline\Plugin\Support\ApprovalNotifier;
use Belayline\Plugin\Support\ArgumentCapGate;
use Belayline\Plugin\Support\DecisionRecorder;
use Belayline\Plugin\Support\PauseSwitch;
use Belayline\Plugin\Support\RateLimitGate;
use Belayline\Plugin\Support\RequestContext;
use Belayline\Plugin\Support\ShadowMode;
use Belayline\Plugin\Support\Tripwires;
use Belayline\Plugin\Support\WindowCounter;
use Belayline\Plugin\Tests\Fakes\FakeIdentityProvider;
use Belayline\Plugin\Tests\Fakes\InMemoryAuditSink;
use Belayline\Plugin\Verdict\Hints;
use Belayline\Plugin\Verdict\VerdictMode;
use Belayline\Plugin\Verdict\VerdictPipeline;
use Belayline\Policy\Tier;
use Belayline\Policy\TierClassifier;
use Belayline\Policy\VerbCatalog;

/**
 * AS-8 (§3.5 item 3): `belayline/check-approval`'s named, exact exemption
 * from the caller's Pack, proven at the {@see VerdictPipeline::judge()} level
 * — independent of any Pack's allow list, denyClass wall, approval rule, rate
 * cap, the site pause, or a tripwire lockout. The ability's OWN scoping, rate
 * limit and audit rules live one layer up
 * ({@see \Belayline\Plugin\Integrations\Self\CheckApprovalAbility})
 * and are covered in CheckApprovalAbilityTest.
 */
final class VerdictPipelineCheckApprovalTest extends TestCase
{
    private const NOW = 1_800_000_000;

    private InMemoryAuditSink $sink;

    protected function setUp(): void
    {
        $GLOBALS['wpas_test_options'] = [];
        $GLOBALS['wpas_test_transients'] = [];
        \CounterTableWpdb::install();
        $GLOBALS['wpas_test_actions'] = [];
        $GLOBALS['wpas_test_mail'] = [];
        $GLOBALS['wpas_test_time'] = self::NOW;
        remove_all_filters(PauseSwitch::FILTER);
        remove_all_filters(Tripwires::FILTER);
        RequestContext::reset();

        $this->sink = new InMemoryAuditSink();
    }

    protected function tearDown(): void
    {
        RequestContext::reset();
        remove_all_filters(PauseSwitch::FILTER);
        remove_all_filters(Tripwires::FILTER);
        $GLOBALS['wpas_test_options'] = [];
        $GLOBALS['wpas_test_transients'] = [];
        $GLOBALS['wpas_test_actions'] = [];
        $GLOBALS['wpas_test_mail'] = [];
        $GLOBALS['wpas_test_time'] = \time();
    }

    private function pipeline(): VerdictPipeline
    {
        // No verb catalog entry for the check-approval verb on purpose: the
        // exemption must never depend on classification, only on the exact
        // verb string.
        $catalog = new VerbCatalog();

        RequestContext::configure(new IdentityChain([
            new FakeIdentityProvider(currentTokens: ['test:token']),
        ]));

        $changes = new AdminChangeRecorder($this->sink);

        return new VerdictPipeline(
            new \Belayline\Gate\Gate(new TierClassifier($catalog)),
            new DecisionRecorder($this->sink),
            null,
            new RateLimitGate(),
            new ArgumentCapGate(),
            new ShadowMode(),
            null,
            new PauseSwitch($changes),
            new Tripwires(new WindowCounter(), $changes, new ApprovalNotifier()),
        );
    }

    /** Every real Pack's allow list omits belayline/*; the default-agent shape allows nothing. */
    private function fenceless(): Pack
    {
        return new Pack(
            name: 'woo-default-agent',
            allow: ['woocommerce/*'],
            denyClass: ['tier1', 'tier2'],
            approvalByClass: ['tier0' => true],
        );
    }

    public function testAllowedDespiteAnAllowListThatOmitsIt(): void
    {
        $verdict = $this->pipeline()->judge(
            VerdictPipeline::CHECK_APPROVAL_VERB,
            ['approval_id' => 'apr_1'],
            $this->fenceless(),
            Hints::none(),
            VerdictMode::Claim,
        );

        $this->assertTrue($verdict->proceeds());
        $this->assertSame(Outcome::Allow, $verdict->decision->outcome);
        $this->assertSame(Tier::Reversible, $verdict->decision->tier);
        $this->assertNull($verdict->error());
    }

    public function testAllowedUnderAPackThatDenyClassWallsEverything(): void
    {
        $wall = new Pack(name: 'locked-down', allow: [], denyClass: ['tier0', 'tier1', 'tier2']);

        $verdict = $this->pipeline()->judge(
            VerdictPipeline::CHECK_APPROVAL_VERB,
            ['approval_id' => 'apr_1'],
            $wall,
            Hints::none(),
            VerdictMode::Claim,
        );

        $this->assertTrue($verdict->proceeds());
    }

    public function testAllowedDespiteThePacksOwnRateCapAlreadyTripped(): void
    {
        $pack = new Pack(name: 'capped', allow: ['woocommerce/*'], limits: ['calls_per_minute' => 1]);

        // Trip the Pack's own cap on an ordinary verb first.
        $catalogedVerb = 'woocommerce/orders-list';
        $pipeline = $this->pipelineWithVerb($catalogedVerb);
        $pipeline->judge($catalogedVerb, [], $pack, Hints::none(), VerdictMode::Claim);
        $tripped = $pipeline->judge($catalogedVerb, ['x' => 1], $pack, Hints::none(), VerdictMode::Claim);
        $this->assertFalse($tripped->proceeds(), 'sanity: the pack cap really is tripped');

        // The SAME pack, same request, now polling — untouched by that cap.
        $verdict = $pipeline->judge(VerdictPipeline::CHECK_APPROVAL_VERB, ['approval_id' => 'apr_1'], $pack, Hints::none(), VerdictMode::Claim);
        $this->assertTrue($verdict->proceeds());
    }

    private function pipelineWithVerb(string $verb): VerdictPipeline
    {
        $catalog = new VerbCatalog();
        $catalog->register([$verb => Tier::Reversible]);

        RequestContext::configure(new IdentityChain([
            new FakeIdentityProvider(currentTokens: ['test:token']),
        ]));

        $changes = new AdminChangeRecorder($this->sink);

        return new VerdictPipeline(
            new \Belayline\Gate\Gate(new TierClassifier($catalog)),
            new DecisionRecorder($this->sink),
            null,
            new RateLimitGate(),
            new ArgumentCapGate(),
            new ShadowMode(),
            null,
            new PauseSwitch($changes),
            new Tripwires(new WindowCounter(), $changes, new ApprovalNotifier()),
        );
    }

    public function testAnswersWhilePausedAndReportsIt(): void
    {
        $GLOBALS['wpas_test_options'][PauseSwitch::OPTION] = ['since' => self::NOW, 'by' => 7, 'reason' => 'runaway'];

        $verdict = $this->pipeline()->judge(
            VerdictPipeline::CHECK_APPROVAL_VERB,
            ['approval_id' => 'apr_1'],
            $this->fenceless(),
            Hints::none(),
            VerdictMode::Claim,
        );

        $this->assertTrue($verdict->proceeds(), 'the pause must not deny check-approval');
        $this->assertTrue($verdict->paused);
    }

    public function testNotPausedReportsFalse(): void
    {
        $verdict = $this->pipeline()->judge(
            VerdictPipeline::CHECK_APPROVAL_VERB,
            ['approval_id' => 'apr_1'],
            $this->fenceless(),
            Hints::none(),
            VerdictMode::Claim,
        );

        $this->assertFalse($verdict->paused);
    }

    public function testAnswersDespiteADenialLockout(): void
    {
        $pack = new Pack(name: 'reader', allow: []);

        // Trip the lockout with ordinary denials on an unrelated, cataloged verb.
        $locking = $this->pipelineWithVerb('woocommerce/orders-list');
        for ($i = 0; $i < 25; $i++) {
            $locking->judge('woocommerce/orders-list', ['n' => $i], $pack, Hints::none(), VerdictMode::Claim);
        }

        $verdict = $locking->judge(
            VerdictPipeline::CHECK_APPROVAL_VERB,
            ['approval_id' => 'apr_1'],
            $pack,
            Hints::none(),
            VerdictMode::Claim,
        );

        $this->assertTrue($verdict->proceeds(), 'a tripwire lockout must not deny check-approval');
    }

    public function testExemptionIsExactNotTheWholeNamespace(): void
    {
        // Namespace is governed, but only the ONE verb is exempt: any other
        // belayline/* verb the catalog never mapped denies unknown_verb,
        // exactly like any other ungoverned write.
        $verdict = $this->pipeline()->judge(
            'belayline/some-other-verb',
            [],
            $this->fenceless(),
            Hints::none(),
            VerdictMode::Claim,
        );

        $this->assertFalse($verdict->proceeds());
        $this->assertSame(Outcome::Deny, $verdict->decision->outcome);
        $this->assertSame('unknown_verb', $verdict->decision->reason);
    }
}
