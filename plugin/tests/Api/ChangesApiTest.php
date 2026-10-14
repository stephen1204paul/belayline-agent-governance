<?php

declare(strict_types=1);

namespace Belayline\Plugin\Tests\Api;

use PHPUnit\Framework\TestCase;
use Belayline\Changes\UndoItem;
use Belayline\Changes\UndoResult;
use Belayline\Plugin\Api\Changes;
use Belayline\Plugin\Changes\ChangeCapture;
use Belayline\Plugin\Changes\UndoService;
use Belayline\Plugin\Container;
use Belayline\Plugin\Support\PackResolver;
use Belayline\Plugin\Support\RequestContext;
use Belayline\Plugin\Tests\Fakes\InMemoryChangeLedger;
use Belayline\Policy\Tier;
use Belayline\Policy\TierClassifier;
use Belayline\Policy\VerbCatalog;

/**
 * The contract Site Agent 0.4 relies on: reach the API through the global
 * belayline() locator, list a run's changes, preview, then undo from a
 * simulated human request. Nonce and screen capability are the caller's job;
 * the API's own refusals are asserted here.
 */
final class ChangesApiTest extends TestCase
{
    private ChangeCapture $capture;
    private InMemoryChangeLedger $ledger;

    protected function setUp(): void
    {
        RequestContext::reset();
        ChangeCapture::configure(null);
        $GLOBALS['wpas_test_posts'] = [];
        $GLOBALS['wpas_test_current_user_id'] = 1;
        $GLOBALS['wpas_test_user_caps_by_id'] = [1 => ['manage_options' => true], 2 => []];

        $catalog = new VerbCatalog();
        $catalog->register(['t/write' => Tier::SideEffecting]);
        $this->ledger = new InMemoryChangeLedger();
        $this->capture = new ChangeCapture(new TierClassifier($catalog), new PackResolver(), $this->ledger);
        Container::init(null, null, null, new Changes($this->ledger, new UndoService($this->ledger, $this->capture->adapters(), $this->capture)));

        $GLOBALS['wpas_test_posts'][7] = (object) [
            'ID' => 7, 'post_title' => 'A', 'post_type' => 'post', 'post_status' => 'publish',
            'post_password' => '', 'post_modified' => '2026-01-01 00:00:00',
        ];
    }

    protected function tearDown(): void
    {
        Container::reset();
        RequestContext::reset();
        $GLOBALS['wpas_test_user_caps_by_id'] = [];
        $GLOBALS['wpas_test_current_user_id'] = 0;
    }

    private function agentRun(string $title): string
    {
        $this->capture->run('t/write', [], static fn () => wp_update_post(wp_slash(['ID' => 7, 'post_title' => $title])));
        $this->capture->takeSettled('t/write');

        return $this->ledger->rows[count($this->ledger->rows) - 1]['correlation_id'];
    }

    public function testListPreviewAndUndoTheWayAConsumerCallsIt(): void
    {
        $corr = $this->agentRun('B');

        $changes = belayline()?->changes();
        $this->assertNotNull($changes);

        $run = $changes->forCorrelation($corr);
        $this->assertFalse($run->isEmpty());
        $this->assertCount(1, $run);
        $change = $run->all()[0];
        $this->assertSame('post', $change->objectType());
        $this->assertSame('7', $change->objectId());
        $this->assertSame('update', $change->action());
        $this->assertContains(['field' => 'post_title', 'before' => 'A', 'after' => 'B'], $change->fieldDiff());
        $this->assertTrue($change->restorable());
        $this->assertNull($change->reason());

        $this->assertSame($run->all()[0]->id(), $changes->forEvent($change->eventId())->all()[0]->id());

        $preview = $changes->preview($run->scope());
        $this->assertSame([UndoItem::RESTORABLE], array_map(static fn (UndoItem $i): string => $i->status, $preview));
        $this->assertSame('B', $GLOBALS['wpas_test_posts'][7]->post_title, 'preview writes nothing');

        $result = $changes->undo($run->scope(), get_current_user_id());
        $this->assertSame(UndoResult::COMPLETE, $result->status);
        $this->assertSame('A', $GLOBALS['wpas_test_posts'][7]->post_title);
        $this->assertSame($result->eventId, $changes->forCorrelation($corr)->all()[0]->restoredByEvent());
        $this->assertFalse($changes->forCorrelation($corr)->all()[0]->restorable());
        $this->assertSame('already_restored', $changes->forCorrelation($corr)->all()[0]->reason());
    }

    public function testAnUnknownRunIsEmptyNotAnError(): void
    {
        $this->assertTrue(belayline()?->changes()?->forCorrelation('nope')->isEmpty());
    }

    public function testUndoRefusesAUserWhoIsNotTheSignedInOne(): void
    {
        $corr = $this->agentRun('B');
        $changes = belayline()?->changes();

        $result = $changes?->undo($changes->forCorrelation($corr)->scope(), 2);

        $this->assertSame(UndoResult::FORBIDDEN, $result?->status);
        $this->assertSame('B', $GLOBALS['wpas_test_posts'][7]->post_title);
    }

    public function testUndoRefusesWithoutTheCapability(): void
    {
        $corr = $this->agentRun('B');
        $GLOBALS['wpas_test_current_user_id'] = 2;
        $changes = belayline()?->changes();

        $result = $changes?->undo($changes->forCorrelation($corr)->scope(), 2);

        $this->assertSame(UndoResult::FORBIDDEN, $result?->status);
        $this->assertSame(UndoResult::REASON_CAPABILITY, $result->reason);
        $this->assertSame('B', $GLOBALS['wpas_test_posts'][7]->post_title);
    }

    public function testUndoRefusesAnAgentContext(): void
    {
        $corr = $this->agentRun('B');
        $changes = belayline()?->changes();
        RequestContext::configurePrincipalResolver(static fn (): string => 'app_pw_1');

        $result = $changes?->undo($changes->forCorrelation($corr)->scope(), 1);

        $this->assertSame(UndoResult::FORBIDDEN, $result?->status);
        $this->assertSame(UndoResult::REASON_AGENT_CONTEXT, $result->reason);
        $this->assertSame('B', $GLOBALS['wpas_test_posts'][7]->post_title);
    }

    public function testAContainerWithoutHistoryExposesNull(): void
    {
        Container::init(null);
        $this->assertNull(belayline()?->changes());
    }
}
