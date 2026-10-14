<?php

declare(strict_types=1);

namespace Belayline\Plugin\Tests\Changes;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use Belayline\Changes\SnapshotRef;
use Belayline\Plugin\Changes\ChangeAdapters;
use Belayline\Plugin\Changes\ChangeCapture;
use Belayline\Plugin\Support\PackResolver;
use Belayline\Plugin\Support\RequestContext;
use Belayline\Plugin\Tests\Fakes\FakeStoreSnapshotter;
use Belayline\Plugin\Tests\Fakes\InMemoryChangeLedger;
use Belayline\Policy\Tier;
use Belayline\Policy\TierClassifier;
use Belayline\Policy\VerbCatalog;

/** Args-based capture for platforms WordPress hooks cannot see. */
final class SnapshotterTest extends TestCase
{
    private InMemoryChangeLedger $ledger;
    private FakeStoreSnapshotter $snap;
    private ChangeCapture $capture;

    protected function setUp(): void
    {
        RequestContext::reset();
        $GLOBALS['wpas_test_posts'] = [];
        $GLOBALS['wpas_test_added_actions'] = [];
        $GLOBALS['wpas_test_filters'] = [];
        $GLOBALS['store'] = ['price:1' => ['price' => '10'], 'price:2' => ['price' => '20']];

        $catalog = new VerbCatalog();
        $catalog->register(['shop/set-price' => Tier::SideEffecting]);
        $this->snap = new FakeStoreSnapshotter();
        $snap = $this->snap;
        add_filter(ChangeAdapters::FILTER, static fn (array $a): array => [...$a, $snap]);

        $this->ledger = new InMemoryChangeLedger();
        $this->capture = new ChangeCapture(new TierClassifier($catalog), new PackResolver(), $this->ledger);
    }

    /** @return list<array<string, mixed>> */
    private function runCall(array $args, callable $execute): array
    {
        $this->capture->run('shop/set-price', $args, $execute);

        return $this->ledger->rows;
    }

    public function testAChangedRefRecordsABeforeAfterDiffAndAnUnchangedOneRecordsNothing(): void
    {
        $rows = $this->runCall(['ids' => [1, 2]], static function (): void {
            $GLOBALS['store']['price:1'] = ['price' => '15'];
        });

        $this->assertCount(1, $rows);
        $this->assertSame('price', $rows[0]['object_type']);
        $this->assertSame('1', $rows[0]['object_id']);
        $this->assertSame('update', $rows[0]['action']);
        $this->assertSame('["price"]', $rows[0]['changed_fields']);
        $this->assertSame('{"price":"10"}', $rows[0]['before_json']);
        $this->assertSame('{"price":"15"}', $rows[0]['after_json']);
        $this->assertSame(1, $rows[0]['restorable']);
    }

    public function testACreationAndADeletionAreRecordedAsSuch(): void
    {
        $GLOBALS['store']['price:3'] = null;
        $rows = $this->runCall(['ids' => [2, 3]], static function (): void {
            $GLOBALS['store']['price:2'] = null;
            $GLOBALS['store']['price:3'] = ['price' => '5'];
        });

        $byId = array_column($rows, null, 'object_id');
        $this->assertSame('delete', $byId['2']['action']);
        $this->assertSame('create', $byId['3']['action']);
    }

    public function testASnapshotterOnlyHandlesItsOwnAbilities(): void
    {
        $catalog = new VerbCatalog();
        $catalog->register(['shop/other' => Tier::SideEffecting]);
        $capture = new ChangeCapture(new TierClassifier($catalog), new PackResolver(), $this->ledger);

        $capture->run('shop/other', ['ids' => [1]], static function (): void {
            $GLOBALS['store']['price:1'] = ['price' => '99'];
        });

        $this->assertSame([], $this->ledger->rows);
    }

    public function testAHookChangeForAnObjectNoSnapshotterClaimedIsStillRecorded(): void
    {
        $rows = $this->runCall(['ids' => [1]], function (): void {
            $GLOBALS['store']['price:1'] = ['price' => '15'];
            foreach ($this->capture->adapters()->capture() as $adapter) {
                if ($adapter instanceof \Belayline\Plugin\Changes\Adapters\PostAdapter) {
                    $adapter->onPostUpdated(7, (object) ['post_title' => 'B', 'post_type' => 'post', 'post_status' => 'publish'], (object) ['post_title' => 'A', 'post_type' => 'post', 'post_status' => 'publish']);
                }
            }
        });

        $this->assertSame(['post', 'price'], array_column($rows, 'object_type'), 'an unclaimed hook change is kept, in order, before the snapshot change');
    }

    public function testAHookRecordForAClaimedRefIsDropped(): void
    {
        // The fake declares a `post:7` ref too; a hook-recorded change for it must not double up.
        $this->snap->extraRefs = [new SnapshotRef('post', '7')];
        $GLOBALS['store']['post:7'] = ['post_title' => 'A'];

        $rows = $this->runCall(['ids' => []], function (): void {
            $GLOBALS['store']['post:7'] = ['post_title' => 'B'];
            foreach ($this->capture->adapters()->capture() as $adapter) {
                if ($adapter instanceof \Belayline\Plugin\Changes\Adapters\PostAdapter) {
                    $adapter->onPostUpdated(7, (object) ['ID' => 7, 'post_title' => 'B', 'post_type' => 'post', 'post_status' => 'publish'], (object) ['ID' => 7, 'post_title' => 'A', 'post_type' => 'post', 'post_status' => 'publish']);
                }
            }
        });

        $this->assertCount(1, $rows, 'one record for post 7, the snapshot one');
        $this->assertSame('{"post_title":"A"}', $rows[0]['before_json']);
    }

    public function testASnapshotterThatThrowsBecomesAMarkerAndTheCallStillRuns(): void
    {
        $this->snap->failOn = 'before';
        $ran = false;

        $rows = $this->runCall(['ids' => [1]], static function () use (&$ran): void {
            $ran = true;
        });

        $this->assertTrue($ran);
        $this->assertCount(1, $rows);
        $this->assertSame('capture_error', $rows[0]['object_id']);
        $this->assertSame(0, $rows[0]['restorable']);
    }

    public function testAnAfterSnapshotFailureIsAMarkerToo(): void
    {
        $this->snap->failOn = 'after';

        $rows = $this->runCall(['ids' => [1]], static function (): void {
            $GLOBALS['store']['price:1'] = ['price' => '15'];
        });

        $this->assertSame(['capture_error'], array_column($rows, 'object_id'));
    }

    public function testACallThatThrowsStillRecordsWhatChanged(): void
    {
        try {
            $this->runCall(['ids' => [1]], static function (): void {
                $GLOBALS['store']['price:1'] = ['price' => '15'];
                throw new RuntimeException('boom');
            });
            $this->fail('must propagate');
        } catch (RuntimeException) {
            $this->assertCount(1, $this->ledger->rows);
        }
    }
}
