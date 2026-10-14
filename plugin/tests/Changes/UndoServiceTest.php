<?php

declare(strict_types=1);

namespace Belayline\Plugin\Tests\Changes;

use PHPUnit\Framework\TestCase;
use Belayline\Audit\AuditRecord;
use Belayline\Audit\HashChain;
use Belayline\Changes\CaptureWindow;
use Belayline\Changes\Change;
use Belayline\Changes\ChangeAction;
use Belayline\Changes\StoredChange;
use Belayline\Changes\UndoItem;
use Belayline\Changes\UndoResult;
use Belayline\Changes\UndoScope;
use Belayline\Plugin\Changes\ChangeAdapters;
use Belayline\Plugin\Changes\ChangeCapture;
use Belayline\Plugin\Changes\UndoService;
use Belayline\Plugin\Support\PackResolver;
use Belayline\Plugin\Support\RequestContext;
use Belayline\Plugin\Tests\Fakes\ChainingSink;
use Belayline\Plugin\Tests\Fakes\InMemoryChangeLedger;
use Belayline\Plugin\Tests\Fakes\WidgetAdapter;
use Belayline\Policy\Tier;
use Belayline\Policy\TierClassifier;
use Belayline\Policy\VerbCatalog;

/** The undo engine against the write shims in tests/bootstrap.php, which dispatch the hooks the adapters listen on. */
final class UndoServiceTest extends TestCase
{
    private InMemoryChangeLedger $ledger;
    private ChangeCapture $capture;
    private UndoService $undo;
    private ChainingSink $sink;

    protected function setUp(): void
    {
        RequestContext::reset();
        ChangeCapture::configure(null);
        $GLOBALS['wpas_test_posts'] = [];
        $GLOBALS['wpas_test_terms'] = [];
        $GLOBALS['wpas_test_meta'] = [];
        $GLOBALS['wpas_test_options'] = [];
        $GLOBALS['wpas_test_added_actions'] = [];
        $GLOBALS['wpas_test_filters'] = [];
        $GLOBALS['wpas_test_current_user_id'] = 1;
        $GLOBALS['wpas_test_user_caps_by_id'] = [1 => ['manage_options' => true]];

        $catalog = new VerbCatalog();
        $catalog->register(['t/write' => Tier::SideEffecting]);

        $this->ledger = new InMemoryChangeLedger();
        $this->capture = new ChangeCapture(new TierClassifier($catalog), new PackResolver(), $this->ledger);
        $this->sink = new ChainingSink();
        $this->undo = new UndoService($this->ledger, $this->capture->adapters(), $this->capture, $this->sink);
    }

    protected function tearDown(): void
    {
        RequestContext::reset();
        $GLOBALS['wpas_test_user_caps_by_id'] = [];
        $GLOBALS['wpas_test_current_user_id'] = 0;
    }

    private static function seedPost(int $id, string $title, string $status = 'publish'): void
    {
        $GLOBALS['wpas_test_posts'][$id] = (object) [
            'ID' => $id, 'post_title' => $title, 'post_type' => 'post', 'post_status' => $status,
            'post_password' => '', 'post_modified' => '2026-01-01 00:00:00',
        ];
    }

    private static function title(int $id): string
    {
        return (string) $GLOBALS['wpas_test_posts'][$id]->post_title;
    }

    private static function setTitle(int $id, string $title): void
    {
        wp_update_post(wp_slash(['ID' => $id, 'post_title' => $title]));
    }

    /** Run a governed call and return the event id its window settled under. */
    private function call(callable $writes): string
    {
        $this->capture->run('t/write', [], $writes);

        return (string) $this->capture->takeSettled('t/write')['event_id'];
    }

    public function testDriftRefusesWithAThreeWayDiffAndWritesNothing(): void
    {
        self::seedPost(7, 'A');
        $event = $this->call(static fn () => self::setTitle(7, 'B'));
        $GLOBALS['wpas_test_posts'][7]->post_title = 'C'; // a human edit since

        $result = $this->undo->undo(UndoScope::event($event), 1);

        $this->assertSame(UndoResult::REFUSED, $result->status);
        $item = $result->items[0];
        $this->assertSame(UndoItem::FAILED, $item->status);
        $this->assertSame(UndoItem::REASON_DRIFT, $item->reason);
        $this->assertSame([['field' => 'post_title', 'before' => 'A', 'after' => 'B', 'current' => 'C']], $item->drift);
        $this->assertSame('C', self::title(7));
        $this->assertSame([], $this->sink->records, 'a run that restored nothing writes no audit row');
    }

    public function testATruncatedChangeIsNeverRestored(): void
    {
        $GLOBALS['wpas_test_options']['big'] = 'x';
        $event = $this->call(static function (): void {
            $new = str_repeat('y', Change::MAX_BODY_BYTES + 10);
            $GLOBALS['wpas_test_options']['big'] = $new;
            wpas_test_fire('update_option', 'big', 'x', $new);
        });

        $result = $this->undo->undo(UndoScope::event($event), 1);

        $this->assertSame(UndoResult::REFUSED, $result->status);
        $this->assertSame(UndoItem::SKIPPED, $result->items[0]->status);
        $this->assertSame(Change::REASON_TRUNCATED, $result->items[0]->reason);
        $this->assertSame(Change::MAX_BODY_BYTES + 10, strlen((string) $GLOBALS['wpas_test_options']['big']));
    }

    public function testAnIrreversibleChangeIsSkippedWithItsReason(): void
    {
        $window = new CaptureWindow();
        $window->open('evt-x', 'corr-x', false);
        $window->record(Change::capture('order', '9', null, ChangeAction::Irreversible, ['status' => 'a'], ['status' => 'b']));
        $closed = $window->close();
        $this->assertNotNull($closed);
        $this->ledger->insertWindow($closed, '2026-01-01 00:00:00');

        $result = $this->undo->undo(UndoScope::event('evt-x'), 1);

        $this->assertSame(Change::REASON_IRREVERSIBLE, $result->items[0]->reason);
    }

    public function testACreationIsUndoneByMovingItToTheTrash(): void
    {
        $event = $this->call(static function (): void {
            self::seedPost(11, 'New');
            wpas_test_fire('wp_insert_post', 11, $GLOBALS['wpas_test_posts'][11], false);
        });

        $result = $this->undo->undo(UndoScope::event($event), 1);

        $this->assertSame(UndoResult::COMPLETE, $result->status);
        $this->assertArrayHasKey(11, $GLOBALS['wpas_test_posts'], 'never deleted');
        $this->assertSame('trash', $GLOBALS['wpas_test_posts'][11]->post_status);
    }

    public function testACreatedTermIsRefusedBecauseTermsHaveNoTrash(): void
    {
        $event = $this->call(static function (): void {
            $GLOBALS['wpas_test_terms'][4] = (object) ['term_id' => 4, 'name' => 'T', 'slug' => 't', 'taxonomy' => 'post_tag', 'description' => '', 'parent' => 0, 'count' => 0];
            wpas_test_fire('created_term', 4, 4, 'post_tag');
        });

        $result = $this->undo->undo(UndoScope::event($event), 1);

        $this->assertSame(UndoResult::REFUSED, $result->status);
        $this->assertSame('term_creation', $result->items[0]->reason);
        $this->assertArrayHasKey(4, $GLOBALS['wpas_test_terms']);
    }

    public function testAnEditedTermIsRestored(): void
    {
        $GLOBALS['wpas_test_terms'][4] = (object) ['term_id' => 4, 'name' => 'Old', 'slug' => 'old', 'taxonomy' => 'post_tag', 'description' => '', 'parent' => 0, 'count' => 0];
        $event = $this->call(static fn () => wp_update_term(4, 'post_tag', wp_slash(['name' => 'New'])));
        $this->assertSame('New', $GLOBALS['wpas_test_terms'][4]->name);

        $this->assertSame(UndoResult::COMPLETE, $this->undo->undo(UndoScope::event($event), 1)->status);
        $this->assertSame('Old', $GLOBALS['wpas_test_terms'][4]->name);
    }

    public function testUndoingAnUndoRoundTripsAPostTitleAndMeta(): void
    {
        self::seedPost(7, 'A');
        $GLOBALS['wpas_test_meta'][7]['color'] = 'red';
        $first = $this->call(static function (): void {
            self::setTitle(7, 'B');
            update_post_meta(7, 'color', wp_slash('blue'));
        });

        $undone = $this->undo->undo(UndoScope::event($first), 1);
        $this->assertSame(UndoResult::COMPLETE, $undone->status);
        $this->assertSame('A', self::title(7));
        $this->assertSame('red', $GLOBALS['wpas_test_meta'][7]['color']);
        $this->assertCount(2, $this->ledger->forEvent((string) $undone->eventId), 'the undo is itself recorded');

        $redone = $this->undo->undo(UndoScope::event((string) $undone->eventId), 1);
        $this->assertSame(UndoResult::COMPLETE, $redone->status);
        $this->assertSame('B', self::title(7));
        $this->assertSame('blue', $GLOBALS['wpas_test_meta'][7]['color']);
    }

    public function testACorrelationScopeRestoresInReverseOrder(): void
    {
        self::seedPost(7, 'A');
        $this->call(static fn () => self::setTitle(7, 'B'));
        $this->call(static fn () => self::setTitle(7, 'C'));

        $result = $this->undo->undo(UndoScope::correlation(RequestContext::correlation()), 1);

        $this->assertSame(UndoResult::COMPLETE, $result->status);
        $this->assertSame([2, 1], array_map(static fn (UndoItem $i): int => $i->changeId, $result->items));
        $this->assertSame('A', self::title(7), 'C then B reverted in reverse: only reverse order passes the drift check');
    }

    public function testTheUndoRunsUnderItsOwnCorrelationSoItNeverJoinsTheRunItUndid(): void
    {
        self::seedPost(7, 'A');
        $this->call(static fn () => self::setTitle(7, 'B'));
        $this->undo->undo(UndoScope::correlation(RequestContext::correlation()), 1);

        $this->assertCount(1, $this->ledger->forCorrelation(RequestContext::correlation()));
    }

    public function testPartialFailureStopsAndReportsWhatWasRestored(): void
    {
        foreach ([1, 2, 3] as $id) {
            self::seedPost($id, 'A' . $id);
        }
        $event = $this->call(static function (): void {
            foreach ([1, 2, 3] as $id) {
                self::setTitle($id, 'B' . $id);
            }
        });
        $GLOBALS['wpas_test_posts'][2]->post_title = 'edited'; // drift on the middle one

        $result = $this->undo->undo(UndoScope::event($event), 1);

        $this->assertSame(UndoResult::PARTIAL, $result->status);
        $this->assertSame(
            [UndoItem::RESTORED, UndoItem::FAILED, UndoItem::PENDING],
            array_map(static fn (UndoItem $i): string => $i->status, $result->items),
        );
        $this->assertSame('A3', self::title(3));
        $this->assertSame('edited', self::title(2));
        $this->assertSame('B1', self::title(1), 'not attempted after the stop');
        $this->assertSame([3], array_map(static fn (UndoItem $i): int => $i->changeId, $result->restored()));
        $this->assertNull($this->ledger->rows[0]['restored_by_event']);
        $this->assertSame($result->eventId, $this->ledger->rows[2]['restored_by_event']);
    }

    public function testARestoredAuditRowIsWrittenAndTheChainVerifies(): void
    {
        self::seedPost(7, 'A');
        $event = $this->call(static fn () => self::setTitle(7, 'B'));
        $this->sink->append(AuditRecord::execution($event, '2026-01-01T00:00:00Z', 'c', 'p', ['token_id' => null, 'wp_user' => null], 't/write', 1, [], 'success', [], null, 'abc'));

        $result = $this->undo->undo(UndoScope::event($event), 1);

        $this->assertCount(2, $this->sink->records);
        $row = $this->sink->records[1]->toArray();
        $this->assertSame('restored', $row['decision']);
        $this->assertSame($result->eventId, $row['id']);
        $this->assertSame('undo.event', $row['reason']);
        $this->assertSame([1], $row['input']['restored']);
        $this->assertNotNull($row['changes_sha256']);
        $this->assertSame($result->eventId, $this->ledger->rows[0]['restored_by_event']);
        $this->assertTrue(HashChain::verify($this->sink->entries));
    }

    public function testAnAlreadyRestoredChangeIsNotRestoredTwice(): void
    {
        self::seedPost(7, 'A');
        $event = $this->call(static fn () => self::setTitle(7, 'B'));
        $this->undo->undo(UndoScope::event($event), 1);

        $again = $this->undo->undo(UndoScope::event($event), 1);

        $this->assertSame(UndoResult::REFUSED, $again->status);
        $this->assertSame(StoredChange::REASON_ALREADY_RESTORED, $again->items[0]->reason);
    }

    public function testAnAdapterRegisteredThroughTheFilterIsUsed(): void
    {
        $widget = new WidgetAdapter();
        add_filter(ChangeAdapters::FILTER, static fn (array $adapters): array => [...$adapters, $widget]);
        $GLOBALS['widgets'] = ['w1' => 'old'];
        $GLOBALS['wpas_test_user_caps_by_id'][1]['manage_widgets'] = true;

        $event = $this->call(static function () use ($widget): void {
            $widget->write('w1', 'new');
        });
        $this->assertSame('new', $GLOBALS['widgets']['w1']);

        $result = $this->undo->undo(UndoScope::event($event), 1);

        $this->assertSame(UndoResult::COMPLETE, $result->status);
        $this->assertSame('old', $GLOBALS['widgets']['w1']);
        $this->assertSame('widget', $this->ledger->rows[0]['object_type']);
    }

    public function testTheAdaptersOwnCapabilityGatesTheWholeScope(): void
    {
        $widget = new WidgetAdapter();
        add_filter(ChangeAdapters::FILTER, static fn (array $adapters): array => [...$adapters, $widget]);
        $GLOBALS['widgets'] = ['w1' => 'old'];
        self::seedPost(7, 'A');
        $event = $this->call(static function () use ($widget): void {
            self::setTitle(7, 'B');
            $widget->write('w1', 'new');
        });

        $result = $this->undo->undo(UndoScope::event($event), 1); // user 1 has manage_options only

        $this->assertSame(UndoResult::FORBIDDEN, $result->status);
        $this->assertSame(UndoResult::REASON_CAPABILITY, $result->reason);
        $this->assertSame('B', self::title(7), 'nothing written, not even the change the user may undo');

        $GLOBALS['wpas_test_user_caps_by_id'][1]['manage_widgets'] = true;
        $this->assertSame(UndoResult::COMPLETE, $this->undo->undo(UndoScope::event($event), 1)->status);
    }

    public function testOnlyTheLoggedInHumanMayUndo(): void
    {
        self::seedPost(7, 'A');
        $event = $this->call(static fn () => self::setTitle(7, 'B'));

        $this->assertSame(UndoResult::REASON_USER_MISMATCH, $this->undo->undo(UndoScope::event($event), 2)->reason);

        RequestContext::configurePrincipalResolver(static fn (): string => 'app_pw_1');
        $this->assertSame(UndoResult::REASON_AGENT_CONTEXT, $this->undo->undo(UndoScope::event($event), 1)->reason);
        RequestContext::reset();

        $GLOBALS['wpas_test_user_caps_by_id'][1] = [];
        $this->assertSame(UndoResult::REASON_CAPABILITY, $this->undo->undo(UndoScope::event($event), 1)->reason);
        $this->assertSame('B', self::title(7));
    }

    public function testUndoIsRefusedInsideAGovernedCall(): void
    {
        self::seedPost(7, 'A');
        $event = $this->call(static fn () => self::setTitle(7, 'B'));

        $reason = null;
        $this->capture->run('t/write', [], function () use ($event, &$reason): void {
            $reason = $this->undo->undo(UndoScope::event($event), 1)->reason;
        });

        $this->assertSame(UndoResult::REASON_INSIDE_WINDOW, $reason);
        $this->assertSame('B', self::title(7));
    }

    public function testAPermanentlyDeletedPostIsNotRestorable(): void
    {
        self::seedPost(7, 'A');
        $event = $this->call(static function (): void {
            wpas_test_fire('before_delete_post', 7, $GLOBALS['wpas_test_posts'][7]);
            unset($GLOBALS['wpas_test_posts'][7]);
        });

        $result = $this->undo->undo(UndoScope::event($event), 1);

        $this->assertSame('permanent_delete', $result->items[0]->reason);
    }

    public function testPreviewReportsWithoutWriting(): void
    {
        self::seedPost(7, 'A');
        $event = $this->call(static fn () => self::setTitle(7, 'B'));

        $this->assertSame(UndoItem::RESTORABLE, $this->undo->preview(UndoScope::event($event))[0]->status);
        $this->assertSame('B', self::title(7));
    }
}
