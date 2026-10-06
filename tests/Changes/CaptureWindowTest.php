<?php

declare(strict_types=1);

namespace Specflux\AgentSafety\Tests\Changes;

use PHPUnit\Framework\TestCase;
use Specflux\AgentSafety\Changes\CaptureWindow;
use Specflux\AgentSafety\Changes\Change;
use Specflux\AgentSafety\Changes\ChangeAction;
use Specflux\AgentSafety\Changes\ChangeSet;

final class CaptureWindowTest extends TestCase
{
    private static function change(string $id = '1'): Change
    {
        return Change::capture('post', $id, 'post', ChangeAction::Update, ['post_title' => 'a'], ['post_title' => 'b']);
    }

    public function testRecordsOnlyWhileOpen(): void
    {
        $window = new CaptureWindow();
        $window->record(self::change());
        $window->open('evt-1', 'corr-1', false);
        $window->record(self::change());
        $closed = $window->close();
        $window->record(self::change());

        $this->assertNotNull($closed);
        $this->assertCount(1, $closed->changes);
        $this->assertFalse($window->isOpen());
    }

    public function testNestedOpenJoinsTheOuterWindowAndOnlyTheOuterCloseReturnsIt(): void
    {
        $window = new CaptureWindow();

        $this->assertTrue($window->open('evt-outer', 'corr', false));
        $this->assertFalse($window->open('evt-inner', 'corr', true), 'a nested call joins, it does not open');
        $window->record(self::change('1'));
        $this->assertNull($window->close(), 'the inner close does not end the window');
        $this->assertTrue($window->isOpen());
        $window->record(self::change('2'));
        $closed = $window->close();

        $this->assertNotNull($closed);
        $this->assertSame('evt-outer', $closed->eventId, 'inner changes are recorded under the outer event id');
        $this->assertCount(2, $closed->changes);
        $this->assertFalse($window->redactsPii(), "the outer window's PII policy stands");
    }

    public function testACloseWithNothingOpenIsANoOp(): void
    {
        $window = new CaptureWindow();

        $this->assertNull($window->close());
        $this->assertTrue($window->open('evt-1', 'corr', false));
        $this->assertNotNull($window->close());
        $this->assertNull($window->close());
    }

    public function testAbortClosesEvenWhenNested(): void
    {
        $window = new CaptureWindow();
        $window->open('evt-1', 'corr', false);
        $window->open('evt-2', 'corr', false);

        $closed = $window->abort();

        $this->assertNotNull($closed);
        $this->assertSame('evt-1', $closed->eventId);
        $this->assertFalse($window->isOpen());
    }

    public function testTheNextWindowStartsClean(): void
    {
        $window = new CaptureWindow();
        $window->open('evt-1', 'corr', false);
        $window->record(self::change());
        $window->close();

        $window->open('evt-2', 'corr', false);
        $closed = $window->close();

        $this->assertNotNull($closed);
        $this->assertSame('evt-2', $closed->eventId);
        $this->assertSame([], $closed->changes);
    }

    public function testAnEmptyWindowStillHasAHashDistinctFromAChangedOne(): void
    {
        $window = new CaptureWindow();
        $window->open('evt-1', 'corr', false);
        $empty = $window->close();
        $window->open('evt-2', 'corr', false);
        $window->record(self::change());
        $full = $window->close();

        $this->assertNotNull($empty);
        $this->assertNotNull($full);
        $this->assertSame(hash('sha256', '[]'), $empty->sha256(), '"No changes recorded" is representable as a hash of zero rows');
        $this->assertNotSame($empty->sha256(), $full->sha256());
    }

    public function testOverflowKeepsTheCapAndRecordsOneMarker(): void
    {
        $window = new CaptureWindow();
        $window->open('evt-1', 'corr', false);
        for ($i = 0; $i < CaptureWindow::MAX_CHANGES + 10; $i++) {
            $window->record(self::change((string) $i));
        }
        $closed = $window->close();

        $this->assertNotNull($closed);
        $this->assertCount(CaptureWindow::MAX_CHANGES + 1, $closed->changes);
        $marker = $closed->changes[CaptureWindow::MAX_CHANGES];
        $this->assertSame('overflow', $marker->objectId);
        $this->assertFalse($marker->restorable);
    }

    public function testTheHashChangesWhenAnyRecordedFieldChanges(): void
    {
        $a = ChangeSet::hash([self::change('1')]);
        $b = ChangeSet::hash([self::change('2')]);
        $order1 = ChangeSet::hash([self::change('1'), self::change('2')]);
        $order2 = ChangeSet::hash([self::change('2'), self::change('1')]);

        $this->assertNotSame($a, $b);
        $this->assertNotSame($order1, $order2, 'sequence is part of what is committed to');
        $this->assertSame($a, ChangeSet::hash([self::change('1')]), 'deterministic');
    }
}
