<?php

declare(strict_types=1);

namespace Belayline\Plugin\Tests\Audit;

use Belayline\Plugin\Audit\WpdbApprovalStore;
use Belayline\Plugin\Tests\Fakes\SqliteWpdb;
use PHPUnit\Framework\TestCase;

/**
 * Per-request pending TTLs and the once-per-approval expiry hook, against a
 * real (SQLite) table so the assertions are about rows, not SQL text.
 */
final class WpdbApprovalStoreTtlTest extends TestCase
{
    private SqliteWpdb $db;
    private WpdbApprovalStore $store;

    protected function setUp(): void
    {
        remove_all_filters('belayline_pending_ttl_seconds');
        $GLOBALS['wpas_test_actions'] = [];
        $this->db = new SqliteWpdb();
        $this->store = new WpdbApprovalStore($this->db);
    }

    protected function tearDown(): void
    {
        remove_all_filters('belayline_pending_ttl_seconds');
        $GLOBALS['wpas_test_actions'] = [];
    }

    private function request(string $verb = 'demo/refund', string $args = 'h1', string $corr = 'corr_1'): string
    {
        return $this->store->request($verb, $args, 'summary', $corr, 'evt', 'key_1');
    }

    private function ttlOf(string $approvalId): int
    {
        $row = $this->store->get($approvalId);
        $this->assertIsArray($row);

        return strtotime($row['pending_expires_ts'] . ' UTC') - strtotime($row['created_ts'] . ' UTC');
    }

    /** @return list<array<int, mixed>> */
    private function expiredEvents(): array
    {
        return array_values(array_filter(
            $GLOBALS['wpas_test_actions'],
            static fn (array $a): bool => $a[0] === 'belayline_approval_expired',
        ));
    }

    public function testDefaultPendingTtlIsOneHour(): void
    {
        $this->assertEqualsWithDelta(3600, $this->ttlOf($this->request()), 2);
    }

    public function testFilterSetsTheTtlAndReceivesVerbAndCorrelationId(): void
    {
        $seen = [];
        add_filter('belayline_pending_ttl_seconds', static function ($ttl, $verb, $corr) use (&$seen) {
            $seen = [$ttl, $verb, $corr];

            return 7200;
        }, 10, 3);

        $id = $this->request('demo/refund', 'h1', 'corr_xyz');

        $this->assertSame([3600, 'demo/refund', 'corr_xyz'], $seen);
        $this->assertEqualsWithDelta(7200, $this->ttlOf($id), 2);
    }

    public function testTtlIsClampedToSixtySecondsAndThirtyDays(): void
    {
        add_filter('belayline_pending_ttl_seconds', static fn () => 5);
        $low = $this->request('demo/a', 'ha');
        remove_all_filters('belayline_pending_ttl_seconds');

        add_filter('belayline_pending_ttl_seconds', static fn () => 999999999);
        $high = $this->request('demo/b', 'hb');

        $this->assertEqualsWithDelta(60, $this->ttlOf($low), 2);
        $this->assertEqualsWithDelta(30 * 86400, $this->ttlOf($high), 2);
    }

    /** @return array<string, array{mixed}> */
    public static function nonIntResults(): array
    {
        return ['string' => ['7200'], 'float' => [7200.0], 'null' => [null], 'array' => [[7200]], 'bool' => [true]];
    }

    /** @dataProvider nonIntResults */
    public function testNonIntFilterResultFallsBackToTheDefault(mixed $value): void
    {
        add_filter('belayline_pending_ttl_seconds', static fn () => $value);

        $this->assertEqualsWithDelta(3600, $this->ttlOf($this->request()), 2);
    }

    public function testRetryOfAPendingRequestStillReturnsTheExistingApprovalWithItsOriginalTtl(): void
    {
        add_filter('belayline_pending_ttl_seconds', static fn () => 7200);
        $first = $this->request();
        remove_all_filters('belayline_pending_ttl_seconds');

        $second = $this->request();

        $this->assertSame($first, $second);
        $this->assertSame('1', (string) $this->db->get_var('SELECT COUNT(*) FROM wp_belayline_approvals'));
        $this->assertEqualsWithDelta(7200, $this->ttlOf($second), 2, 'the stored per-row TTL is untouched by the retry');
    }

    public function testSweepExpiresEachRowByItsOwnStoredTtl(): void
    {
        add_filter('belayline_pending_ttl_seconds', static fn ($ttl, $verb) => $verb === 'demo/short' ? 60 : 7 * 86400, 10, 2);
        $short = $this->request('demo/short', 'ha');
        $long = $this->request('demo/long', 'hb');

        $this->db->clockOffset = 3600; // an hour on: past the short row, far inside the long one
        $this->store->deleteExpired(gmdate('Y-m-d H:i:s', time() + 3600));

        $this->assertNull($this->store->get($short), 'the lapsed short row is swept');
        $this->assertSame('pending', $this->store->get($long)['status'] ?? null, 'the 7-day row is still live');
        $this->assertSame([[ 'belayline_approval_expired', $short, 'demo/short' ]], $this->expiredEvents());
    }

    public function testExpiredHookFiresOncePerApprovalAcrossTwoSweeps(): void
    {
        $id = $this->request();
        $this->db->exec("UPDATE wp_belayline_approvals SET pending_expires_ts = '2000-01-01 00:00:00'");

        $now = gmdate('Y-m-d H:i:s');
        $this->store->deleteExpired($now);
        $this->store->deleteExpired($now);

        $this->assertSame([[ 'belayline_approval_expired', $id, 'demo/refund' ]], $this->expiredEvents());
    }

    public function testRowAlreadyFlippedByThePendingScreenIsNotAnnouncedTwice(): void
    {
        $id = $this->request();
        $this->db->exec("UPDATE wp_belayline_approvals SET pending_expires_ts = '2000-01-01 00:00:00'");

        $this->assertSame([], $this->store->pending(), 'viewing the queue flips the lapsed row');
        $this->assertSame('expired', $this->store->get($id)['status'] ?? null);
        $this->store->deleteExpired(gmdate('Y-m-d H:i:s'));

        $this->assertCount(1, $this->expiredEvents());
    }

    public function testLivePendingRowsAreNeitherFlippedNorAnnounced(): void
    {
        $id = $this->request();

        $this->store->deleteExpired(gmdate('Y-m-d H:i:s'));

        $this->assertSame('pending', $this->store->get($id)['status'] ?? null);
        $this->assertSame([], $this->expiredEvents());
    }
}
