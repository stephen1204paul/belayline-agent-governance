<?php

declare(strict_types=1);

namespace Belayline\Plugin\Tests\Support;

use InMemoryAuditRowsWpdb;
use PHPUnit\Framework\TestCase;
use Belayline\Plugin\Changes\WpdbChangeStore;
use Belayline\Plugin\Support\ChangePurge;

final class ChangePurgeTest extends TestCase
{
    private const NOW = 1_800_000_000;

    protected function setUp(): void
    {
        $GLOBALS['wpas_test_options'] = [];
        $GLOBALS['wpas_test_cron'] = [];
        $GLOBALS['wpas_test_time'] = self::NOW;
    }

    protected function tearDown(): void
    {
        $GLOBALS['wpas_test_options'] = [];
        $GLOBALS['wpas_test_cron'] = [];
        $GLOBALS['wpas_test_time'] = \time();
    }

    public function testRetentionDefaultsToThirtyDays(): void
    {
        $this->assertSame(30, ChangePurge::retentionDays());
    }

    public function testAnInvalidRetentionValueFallsBackToTheDefaultNeverToZero(): void
    {
        foreach ([0, -5, 'abc', ''] as $bad) {
            $GLOBALS['wpas_test_options'][ChangePurge::RETENTION_OPTION] = $bad;
            $this->assertSame(30, ChangePurge::retentionDays(), 'a garbage value must not mean "delete everything now"');
        }
    }

    public function testRunDeletesRowsOlderThanTheRetentionWindowOnly(): void
    {
        $GLOBALS['wpas_test_options'][ChangePurge::RETENTION_OPTION] = 7;
        $db = new InMemoryAuditRowsWpdb();
        $old = gmdate('Y-m-d H:i:s', self::NOW - 8 * 86400);
        $fresh = gmdate('Y-m-d H:i:s', self::NOW - 6 * 86400);
        $db->seedChanges([
            ['id' => 1, 'event_id' => 'evt-1', 'ts' => $old],
            ['id' => 2, 'event_id' => 'evt-2', 'ts' => $fresh],
        ]);

        $deleted = ChangePurge::run(new WpdbChangeStore($db));

        $this->assertSame(1, $deleted);
        $this->assertSame([2], array_column($db->changeRows(), 'id'));
    }

    public function testActivateIsIdempotentAndDeactivateClears(): void
    {
        ChangePurge::activate();
        $first = $GLOBALS['wpas_test_cron'][ChangePurge::HOOK];
        $GLOBALS['wpas_test_time'] = self::NOW + 100;
        ChangePurge::activate();

        $this->assertSame($first, $GLOBALS['wpas_test_cron'][ChangePurge::HOOK]);

        ChangePurge::deactivate();
        $this->assertArrayNotHasKey(ChangePurge::HOOK, $GLOBALS['wpas_test_cron']);
    }
}
