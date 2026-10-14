<?php

declare(strict_types=1);

namespace Belayline\Tests\Changes;

use PHPUnit\Framework\TestCase;
use Belayline\Changes\CaptureWindow;
use Belayline\Changes\Change;
use Belayline\Changes\ChangeAction;
use Belayline\Changes\Drift;
use Belayline\Changes\StoredChange;

final class DriftTest extends TestCase
{
    private static function stored(?string $reason = null, bool $restorable = true, ?string $restoredBy = null, ChangeAction $action = ChangeAction::Update, ?array $after = ['t' => 'B', 'm' => '1']): StoredChange
    {
        return new StoredChange(1, 'e', 'c', 1, 'post', '7', null, $action, ['t' => 'A', 'm' => '0'], $after, ['t', 'm'], $restorable, $reason, $restoredBy);
    }

    public function testNoDriftWhenCurrentMatchesTheRecordedAfterOnTheChangedFields(): void
    {
        $this->assertSame([], Drift::compare(self::stored(), ['t' => 'B', 'm' => 1, 'other' => 'x']));
    }

    public function testDriftIsAThreeWayDiffAndVolatileFieldsAreIgnored(): void
    {
        $drift = Drift::compare(self::stored(), ['t' => 'C', 'm' => '9'], ['m']);

        $this->assertSame([['field' => 't', 'before' => 'A', 'after' => 'B', 'current' => 'C']], $drift);
    }

    public function testStoredRefusalReasons(): void
    {
        $this->assertNull(self::stored()->refusal());
        $this->assertSame('already_restored', self::stored(restoredBy: 'e2')->refusal());
        $this->assertSame('irreversible', self::stored(action: ChangeAction::Irreversible)->refusal());
        $this->assertSame('truncated', self::stored(after: ['_truncated' => true])->refusal());
        $this->assertSame('redacted', self::stored('redacted', false)->refusal());
        $this->assertSame('not_restorable', self::stored(null, false)->refusal());
    }

    public function testASnapshotterClaimDropsHookRecordsForThatObjectOnly(): void
    {
        $window = new CaptureWindow();
        $window->open('e', 'c', false);
        $window->claim('price', '1');
        $window->record(Change::capture('price', '1', null, ChangeAction::Update, ['a' => 1], ['a' => 2]));
        $window->record(Change::capture('price', '2', null, ChangeAction::Update, ['a' => 1], ['a' => 2]));
        $window->recordSnapshot(Change::capture('price', '1', null, ChangeAction::Update, ['a' => 1], ['a' => 3]));

        $closed = $window->close();

        $this->assertNotNull($closed);
        $this->assertSame(['2', '1'], array_map(static fn (Change $c): string => $c->objectId, $closed->changes));

        $window->open('e2', 'c', false);
        $window->record(Change::capture('price', '1', null, ChangeAction::Update, ['a' => 1], ['a' => 2]));
        $this->assertCount(1, $window->close()->changes ?? [], 'claims do not outlive their window');
    }
}
