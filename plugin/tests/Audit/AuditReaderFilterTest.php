<?php

declare(strict_types=1);

namespace Belayline\Plugin\Tests\Audit;

use Belayline\Audit\AuditDecision;
use Belayline\Audit\AuditRecord;
use Belayline\Plugin\Audit\AuditFilter;
use Belayline\Plugin\Audit\AuditReader;
use Belayline\Plugin\Audit\WpdbAuditSink;
use Belayline\Plugin\Tests\Fakes\SqliteWpdb;
use PHPUnit\Framework\TestCase;

/** The Activity > Calls filters, against real rows in SQLite. */
final class AuditReaderFilterTest extends TestCase
{
    private const NOW = 1_800_000_000;

    private SqliteWpdb $db;
    private AuditReader $reader;
    private int $seq = 0;

    protected function setUp(): void
    {
        $this->db = new SqliteWpdb();
        $this->reader = new AuditReader($this->db);
        $sink = new WpdbAuditSink($this->db);
        $row = function (string $decision, ?string $result, string $ability, ?string $token, string $pack, int $daysAgo = 0) use ($sink): void {
            $ts = gmdate('Y-m-d\TH:i:s\Z', self::NOW - $daysAgo * 86400);
            $actor = ['token_id' => $token, 'wp_user' => 1];
            $id = 'e' . ++$this->seq;
            $sink->append($result !== null
                ? AuditRecord::execution($id, $ts, 'c' . $this->seq, $pack, $actor, $ability, 0, [], $result)
                : AuditRecord::decision($id, $ts, 'c' . $this->seq, $pack, $actor, $ability, 1, [], AuditDecision::from($decision)));
        };
        $row('allowed', 'success', 'woocommerce/orders-list', 'app:aaa', 'commerce');
        $row('allowed', 'failure', 'woocommerce/orders-list', 'app:aaa', 'commerce');
        $row('denied', null, 'core/get-user-info', 'user:7', 'site-readonly');
        $row('pending', null, 'woocommerce/order-refund', 'user:8', 'commerce');
        $this->db->insert('wp_belayline_approvals', ['approval_id' => 'apr_a', 'audit_event_id' => 'e4', 'status' => 'pending', 'verb' => 'woocommerce/order-refund']);
        $row('rejected', null, 'woocommerce/order-refund', 'user:8', 'commerce');
        $row('allowed', 'success', 'specflux-site-agent/update-page', 'app:bbb', 'content', 10);
        $row('allowed', 'success', 'core/get-site-info', null, '');
    }

    /** @param array<string, string> $query */
    private function ids(array $query): array
    {
        $f = AuditFilter::fromRequest($query);

        return array_map(static fn (array $r): string => (string) $r['event_id'], $this->reader->filtered($f, 50, 0, self::NOW));
    }

    public function testNoFilterShowsEverythingNewestFirst(): void
    {
        $this->assertSame(['e7', 'e6', 'e5', 'e4', 'e3', 'e2', 'e1'], $this->ids([]));
        $this->assertSame(7, $this->reader->countFiltered(new AuditFilter(), self::NOW));
    }

    /** The last SELECT the reader sent to the database. */
    private function lastSelect(): string
    {
        $selects = array_values(array_filter($this->db->queries, static fn (string $q): bool => str_starts_with($q, 'SELECT')));

        return (string) end($selects);
    }

    public function testAnEmptyFilterReadsTheLogAloneWalkingThePrimaryKey(): void
    {
        $this->reader->filtered(new AuditFilter(), 50, 0, self::NOW);
        $page = $this->lastSelect();
        $this->reader->countFiltered(new AuditFilter(), self::NOW);
        $count = $this->lastSelect();

        foreach ([$page, $count] as $sql) {
            $this->assertStringNotContainsString('JOIN', $sql);
            $this->assertStringNotContainsString('l.ts', $sql, 'no timestamp predicate without a period filter');
            $this->assertStringNotContainsString('WHERE', $sql);
            $this->assertStringNotContainsString('0 = 0', $sql);
        }
        $this->assertStringContainsString('ORDER BY l.id DESC LIMIT 50 OFFSET 0', $page);
    }

    public function testOnlyTheOutcomeFilterJoinsApprovalsAndOnlyActiveFiltersAppear(): void
    {
        $this->reader->filtered(AuditFilter::fromRequest(['pack' => 'commerce']), 50, 0, self::NOW);
        $sql = $this->lastSelect();
        $this->assertStringNotContainsString('JOIN', $sql);
        $this->assertStringContainsString('l.pack', $sql);
        $this->assertStringNotContainsString('l.ability', $sql);
        $this->assertStringNotContainsString('l.ts', $sql);

        $this->reader->filtered(AuditFilter::fromRequest(['days' => '7']), 50, 0, self::NOW);
        $this->assertStringContainsString('l.ts >=', $this->lastSelect());

        $this->reader->filtered(AuditFilter::fromRequest(['outcome' => 'refused']), 50, 0, self::NOW);
        $sql = $this->lastSelect();
        $this->assertStringContainsString('LEFT JOIN', $sql);
        $this->assertStringNotContainsString('l.ts', $sql);
    }

    public function testEachOutcomeSelectsItsRows(): void
    {
        $this->assertSame(['e7', 'e6', 'e1'], $this->ids(['outcome' => 'ran']));
        $this->assertSame(['e2'], $this->ids(['outcome' => 'failed']));
        $this->assertSame(['e3'], $this->ids(['outcome' => 'refused']), 'a person\'s rejected decision row is not a call');
        $this->assertSame([], $this->ids(['outcome' => 'expired']));
        $this->assertSame(['e4'], $this->ids(['outcome' => 'waiting']));
        $this->assertSame([], $this->ids(['outcome' => 'approved']));
        $this->assertSame(7, count($this->ids(['outcome' => 'nonsense'])), 'an unknown outcome is ignored, not an empty page');
    }

    public function testPackThroughAndPeriodFilters(): void
    {
        $this->assertSame(['e4', 'e5', 'e2', 'e1'], array_values(array_intersect(['e4', 'e5', 'e2', 'e1'], $this->ids(['pack' => 'commerce']))));
        $this->assertCount(4, $this->ids(['pack' => 'commerce']));
        $this->assertSame(['e7'], $this->ids(['pack' => '__none']));
        $this->assertSame(['e3', 'e7'], array_values(array_intersect(['e3', 'e7'], $this->ids(['through' => 'core']))));
        $this->assertCount(2, $this->ids(['through' => 'core']));
        $this->assertCount(4, $this->ids(['through' => 'woocommerce']));
        $this->assertCount(6, $this->ids(['days' => '7']), 'the ten-day-old row falls outside');
    }

    public function testTokenFilters(): void
    {
        $this->assertSame(['e2', 'e1'], $this->ids(['token' => 'app:aaa']));
        $this->assertSame(['e6', 'e2', 'e1'], array_values(array_filter($this->ids(['token' => 'app:aaa,app:bbb']), static fn (string $id): bool => $id !== 'e7')));
        $this->assertSame(['e5', 'e4', 'e3'], $this->ids(['token_prefix' => 'user:']));
        $this->assertSame(7, count($this->ids(['token' => ''])));
    }

    public function testFiltersCombine(): void
    {
        $this->assertSame(['e2'], $this->ids(['pack' => 'commerce', 'outcome' => 'failed', 'token' => 'app:aaa']));
    }

    public function testEveryFilterTogetherAndCountsMatchThePage(): void
    {
        $q = ['days' => '7', 'pack' => 'commerce', 'through' => 'woocommerce', 'token' => 'app:aaa', 'outcome' => 'ran'];
        $this->assertSame(['e1'], $this->ids($q));
        $this->assertSame(1, $this->reader->countFiltered(AuditFilter::fromRequest($q), self::NOW));
        foreach ([[], ['pack' => 'commerce'], ['outcome' => 'ran'], ['days' => '7'], ['token_prefix' => 'user:'], ['through' => 'core', 'outcome' => 'refused']] as $query) {
            $this->assertSame(count($this->ids($query)), $this->reader->countFiltered(AuditFilter::fromRequest($query), self::NOW));
        }
    }

    public function testLikeWildcardsInInputAreLiteral(): void
    {
        $this->assertSame([], $this->ids(['token' => 'app:%']), '% is not a wildcard in a token filter');
        $this->assertSame([], $this->ids(['through' => 'wo%']));
    }

    public function testPaging(): void
    {
        $page = $this->reader->filtered(new AuditFilter(), 3, 3, self::NOW);

        $this->assertSame(['e4', 'e3', 'e2'], array_map(static fn (array $r): string => (string) $r['event_id'], $page));
    }

    public function testPacksListsDistinctNonEmptyPackNames(): void
    {
        $this->assertSame(['commerce', 'content', 'site-readonly'], $this->reader->packs());
    }
}
