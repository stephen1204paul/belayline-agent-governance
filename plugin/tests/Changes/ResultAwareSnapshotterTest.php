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
use Belayline\Plugin\Tests\Fakes\FakeCreatingSnapshotter;
use Belayline\Plugin\Tests\Fakes\InMemoryChangeLedger;
use Belayline\Policy\Tier;
use Belayline\Policy\TierClassifier;
use Belayline\Policy\VerbCatalog;

/** Creates whose id exists only in the call's result. */
final class ResultAwareSnapshotterTest extends TestCase
{
    private InMemoryChangeLedger $ledger;
    private FakeCreatingSnapshotter $snap;
    private ChangeCapture $capture;

    protected function setUp(): void
    {
        RequestContext::reset();
        $GLOBALS['wpas_test_posts'] = [];
        $GLOBALS['wpas_test_added_actions'] = [];
        $GLOBALS['wpas_test_filters'] = [];
        $GLOBALS['store'] = [];

        $catalog = new VerbCatalog();
        $catalog->register(['shop/create-price' => Tier::SideEffecting, 'shop/set-price' => Tier::SideEffecting]);
        $this->snap = new FakeCreatingSnapshotter();
        $snap = $this->snap;
        add_filter(ChangeAdapters::FILTER, static fn (array $a): array => [...$a, $snap]);

        $this->ledger = new InMemoryChangeLedger();
        $this->capture = new ChangeCapture(new TierClassifier($catalog), new PackResolver(), $this->ledger);
    }

    public function testACreateIsRecordedAsAbsentBeforeAndSnapshotAfter(): void
    {
        $result = $this->capture->run('shop/create-price', [], static function (): array {
            $GLOBALS['store']['price:new'] = ['amount' => '10'];

            return ['id' => 'new'];
        });

        $this->assertSame(['id' => 'new'], $result, 'result is returned unchanged');
        $this->assertCount(1, $this->ledger->rows);
        $row = $this->ledger->rows[0];
        $this->assertSame('price', $row['object_type']);
        $this->assertSame('new', $row['object_id']);
        $this->assertSame('create', $row['action']);
        $this->assertSame('{"amount":"10"}', $row['after_json']);
    }

    public function testAWpErrorResultRecordsNothing(): void
    {
        $GLOBALS['store']['price:new'] = ['amount' => '10'];
        $this->capture->run('shop/create-price', [], static fn () => new \WP_Error('x', 'failed'));

        $this->assertSame([], $this->ledger->rows);
    }

    public function testAThrowingRefsFromResultIsACaptureErrorAndTheResultSurvives(): void
    {
        $this->snap->failRefsFromResult = true;
        $result = $this->capture->run('shop/create-price', [], static fn (): array => ['id' => 'new']);

        $this->assertSame(['id' => 'new'], $result);
        $this->assertSame(['capture_error'], array_column($this->ledger->rows, 'object_id'));
    }

    public function testARefAlreadyDeclaredUpFrontIsNotDuplicated(): void
    {
        $this->snap->argRefs = [new SnapshotRef('price', 'new')];
        $this->capture->run('shop/create-price', [], static function (): array {
            $GLOBALS['store']['price:new'] = ['amount' => '10'];

            return ['id' => 'new'];
        });

        $this->assertCount(1, $this->ledger->rows);
        $this->assertSame('create', $this->ledger->rows[0]['action']);
    }

    public function testAThrownCallAsksForNoResultRefs(): void
    {
        try {
            $this->capture->run('shop/create-price', [], static function (): void {
                throw new RuntimeException('boom');
            });
            $this->fail('must propagate');
        } catch (RuntimeException) {
            $this->assertSame([], $this->ledger->rows);
        }
    }

    public function testAPlainSnapshotterIsUnaffected(): void
    {
        $GLOBALS['store']['price:1'] = ['price' => '10'];
        $plain = new \Belayline\Plugin\Tests\Fakes\FakeStoreSnapshotter();
        $GLOBALS['wpas_test_filters'] = [];
        add_filter(ChangeAdapters::FILTER, static fn (array $a): array => [...$a, $plain]);
        $capture = new ChangeCapture(new TierClassifier($this->catalog()), new PackResolver(), $this->ledger);

        $capture->run('shop/set-price', ['ids' => [1]], static function (): array {
            $GLOBALS['store']['price:1'] = ['price' => '15'];

            return ['id' => 'ignored'];
        });

        $this->assertCount(1, $this->ledger->rows);
        $this->assertSame('update', $this->ledger->rows[0]['action']);
    }

    private function catalog(): VerbCatalog
    {
        $catalog = new VerbCatalog();
        $catalog->register(['shop/set-price' => Tier::SideEffecting]);

        return $catalog;
    }
}
