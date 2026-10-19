<?php

declare(strict_types=1);

namespace Belayline\Plugin\Tests\Admin\Map;

use Belayline\Audit\AuditDecision;
use Belayline\Audit\AuditRecord;
use Belayline\Plugin\Admin\Map\CallOutcome;
use Belayline\Plugin\Admin\Map\MapData;
use Belayline\Plugin\Admin\Map\WhoResolver;
use Belayline\Plugin\Audit\WpdbAuditSink;
use Belayline\Plugin\Tests\Fakes\SqliteWpdb;
use PHPUnit\Framework\TestCase;

/**
 * The Overview map: outcome mapping, who/through/pack derivation, the 6-per-lane
 * cap, the 7-day window and the cache. The rows are written through the real
 * audit sink into SQLite, so the GROUP BY runs against the real record shape.
 */
final class MapDataTest extends TestCase
{
    private const NOW = 1_800_000_000; // 2027-01-15 08:00:00 UTC

    private SqliteWpdb $db;
    private WpdbAuditSink $sink;
    private int $seq = 0;

    protected function setUp(): void
    {
        $GLOBALS['wpas_test_transients'] = [];
        $GLOBALS['wpas_test_time'] = self::NOW;
        remove_all_filters('belayline_map_who');
        $this->db = new SqliteWpdb();
        $this->sink = new WpdbAuditSink($this->db);
    }

    protected function tearDown(): void
    {
        $GLOBALS['wpas_test_transients'] = [];
        $GLOBALS['wpas_test_time'] = time();
        remove_all_filters('belayline_map_who');
    }

    private function resolver(): WhoResolver
    {
        return new WhoResolver(
            static fn (): array => ['app:uuid-claude' => 'Claude', 'app:uuid-gpt' => 'ChatGPT', 'app:uuid-claude2' => 'Claude'],
            static fn (int $id): ?string => [1 => 'Stephen', 2 => 'Dana', 3 => 'Lee', 4 => 'Kim'][$id] ?? null,
            static fn (array $ids): array => array_fill_keys($ids, 'ck_abcd1234'),
        );
    }

    /** One audit row through the real sink. */
    private function row(string $decision, ?string $result, string $ability, ?string $token, string $pack = 'content', int $daysAgo = 0, array $input = [], ?string $approval = null, bool $dryRun = false, ?string $via = null): void
    {
        $ts = gmdate('Y-m-d\TH:i:s\Z', self::NOW - $daysAgo * 86400 - 60);
        $actor = ['token_id' => $token, 'wp_user' => 1, 'env_type' => 'production', 'env_mismatch' => false];
        if ($via !== null) {
            $actor['via'] = $via;
        }
        $id = 'evt_' . ++$this->seq;
        $record = $result !== null
            ? AuditRecord::execution($id, $ts, 'corr_' . $this->seq, $pack, $actor, $ability, 0, $input, $result)
            : AuditRecord::decision($id, $ts, 'corr_' . $this->seq, $pack, $actor, $ability, 1, $input, AuditDecision::from($decision), dryRun: $dryRun);
        $this->sink->append($record);
        if ($approval !== null) {
            // The approvals row that the pending audit row filed (audit_event_id = the row's event id).
            $this->db->insert('wp_belayline_approvals', ['approval_id' => 'apr_' . $this->seq, 'audit_event_id' => $id, 'status' => $approval, 'verb' => $ability]);
        }
    }

    /** @return array<string, mixed> */
    private function map(): array
    {
        return (new MapData($this->db, $this->resolver()))->compute(self::NOW);
    }

    /** @return array<string, array<string, int>> lane => id => count */
    private static function counts(array $map): array
    {
        $out = [];
        foreach ($map['lanes'] as $lane => $nodes) {
            foreach ($nodes as $n) {
                $out[$lane][$n['id']] = $n['count'];
            }
        }

        return $out;
    }

    public function testOutcomeVocabularyMapping(): void
    {
        $this->assertSame('ran', CallOutcome::classify('allowed', 'success'));
        $this->assertSame('ran', CallOutcome::classify('allowed', null));
        $this->assertSame('failed', CallOutcome::classify('allowed', 'failure'));
        $this->assertSame('failed', CallOutcome::classify('allowed', 'error'));
        $this->assertSame('failed', CallOutcome::classify('allowed', 'error: rest_forbidden'));
        $this->assertSame('failed', CallOutcome::classify('allowed', 'something-new'), 'an unknown result fails visible');
        $this->assertSame('waiting', CallOutcome::classify('pending', null));
        $this->assertSame('approved', CallOutcome::classify('approved', null));
        $this->assertSame('refused', CallOutcome::classify('denied', null));
        $this->assertSame('refused', CallOutcome::classify('rejected', null));
        foreach (['grant', 'admin', 'restored', 'stale', 'bogus'] as $notACall) {
            $this->assertNull(CallOutcome::classify($notACall, null), $notACall . ' is not an agent call');
        }
    }

    public function testOnlyThreeDecisionsCanBeACallAndTheOthersNeverAre(): void
    {
        $this->assertSame(['allowed', 'denied', 'pending'], CallOutcome::CALL_DECISIONS);
        $notCalls = array_map(static fn (AuditDecision $d): string => $d->value, array_filter(AuditDecision::cases(), static fn (AuditDecision $d): bool => !in_array($d->value, CallOutcome::CALL_DECISIONS, true)));
        $this->assertSame(['approved', 'rejected', 'grant', 'admin', 'restored', 'stale'], array_values($notCalls), 'a new audit decision must be placed deliberately');

        foreach ($notCalls as $decision) {
            $this->row($decision, null, 'core/get-site-info', 'user:1');
        }
        $this->assertSame(0, $this->map()['total'], 'a person\'s decision or a settings change is not a call');
    }

    /** @return array<string, array{0: ?string, 1: ?string}> approval status => [outcome id or null for not counted] */
    public static function approvalEndings(): array
    {
        return [
            'still waiting' => ['pending', 'o-waiting'],
            'approved, not run yet' => ['approved', 'o-approved'],
            'running now' => ['in_flight', 'o-approved'],
            'rejected by a person' => ['rejected', 'o-refused'],
            'ran after approval: the run is the call' => ['consumed', null],
            'expired' => ['expired', 'o-expired'],
            'went stale' => ['stale', 'o-expired'],
            'voided' => ['void_environment', 'o-expired'],
            'approval row already swept' => [null, 'o-expired'],
        ];
    }

    /** @dataProvider approvalEndings */
    public function testAHeldRequestIsOneCallWhoseOutcomeIsHowItsApprovalEnded(?string $status, ?string $outcome): void
    {
        $this->row('pending', null, 'woocommerce/order-refund', 'user:1', 'commerce', 0, [], $status);
        // The human decision rows are written too, and must not add calls.
        $this->row('approved', null, 'woocommerce/order-refund', 'user:1', 'commerce');
        $this->row('rejected', null, 'woocommerce/order-refund', 'user:1', 'commerce');

        $map = $this->map();

        if ($outcome === null) {
            $this->assertSame(0, $map['total']);
            $this->assertSame('', $map['tuples']);

            return;
        }
        $this->assertSame(1, $map['total']);
        $this->assertSame('w-user-1,t-woocommerce,p-commerce,' . $outcome . ',1', $map['tuples']);
    }

    public function testAnApprovedCallThatRanIsCountedOnceThroughItsRun(): void
    {
        $this->row('pending', null, 'woocommerce/order-refund', 'user:1', 'commerce', 0, [], 'consumed');
        $this->row('approved', null, 'woocommerce/order-refund', 'user:1', 'commerce');
        $this->row('allowed', 'success', 'woocommerce/order-refund', 'user:1', 'commerce');

        $map = $this->map();

        $this->assertSame(1, $map['total'], 'request, decision and run are one call');
        $this->assertSame('w-user-1,t-woocommerce,p-commerce,o-ran,1', $map['tuples']);
    }

    public function testShadowModeWouldRefuseIsItsOwnBoxAndNotAnotherCall(): void
    {
        $this->row('allowed', 'success', 'core/get-user-info', 'user:1', 'site-readonly');
        $this->row('denied', null, 'core/get-user-info', 'user:1', 'site-readonly', 0, [], null, true);
        $this->row('pending', null, 'woocommerce/order-refund', 'user:1', 'commerce', 0, [], 'expired', true);
        $this->row('denied', null, 'core/get-user-info', 'user:1', 'site-readonly');

        $map = $this->map();

        $this->assertSame(2, $map['total'], 'the shadowed call ran (Ran) and one real refusal; the observations are not extra calls');
        $c = self::counts($map);
        $this->assertSame(2, $c['outcome']['o-would']);
        $this->assertSame(1, $c['outcome']['o-ran']);
        $this->assertSame(1, $c['outcome']['o-refused']);
        $this->assertArrayNotHasKey('o-expired', $c['outcome'], 'a shadow-mode request is not an expired approval');
    }

    public function testExpiredAndWouldRefuseBoxesAppearOnlyWhenTheyHaveCalls(): void
    {
        $this->row('allowed', 'success', 'core/get-site-info', 'user:1');

        $ids = array_column($this->map()['lanes']['outcome'], 'id');

        $this->assertSame(['o-ran', 'o-approved', 'o-waiting', 'o-refused', 'o-failed'], $ids);
    }

    public function testAnInjectedDryRunKeyInAgentInputCannotHideARefusal(): void
    {
        $this->row('denied', null, 'core/get-user-info', 'user:1', 'site-readonly', 0, ['x' => ['dry_run' => true, 'decision' => 'denied']]);

        $this->assertSame('w-user-1,t-core,p-site-readonly,o-refused,1', $this->map()['tuples']);
    }

    public function testAggregatesWhoThroughPackAndOutcomeIntoTuples(): void
    {
        $this->row('allowed', 'success', 'specflux-site-agent/update-page', 'app:uuid-claude');
        $this->row('allowed', 'success', 'specflux-site-agent/update-page', 'app:uuid-claude');
        $this->row('allowed', 'failure', 'woocommerce/orders-list', 'app:uuid-gpt', 'commerce');
        $this->row('denied', null, 'core/get-user-info', 'user:1', 'site-readonly');
        $this->row('pending', null, 'woocommerce/order-refund', 'user:2', 'commerce', 0, [], 'pending');
        $this->row('pending', null, 'woocommerce/order-refund', 'user:1', 'commerce', 0, [], 'approved');
        // Not agent calls: left out of the map.
        $this->row('admin', null, 'belayline_shadow_packs', 'user:1', 'admin');
        $this->row('grant', null, 'woocommerce/order-refund', 'user:1', 'grants');

        $map = $this->map();

        $this->assertSame(6, $map['total'], 'two ran, one failed, one refused, one waiting, one approved-and-not-yet-run; the admin and grant rows are not calls');
        $tuples = explode(';', $map['tuples']);
        $this->assertContains('w-app-claude,t-specflux-site-agent,p-content,o-ran,2', $tuples);
        $this->assertContains('w-app-chatgpt,t-woocommerce,p-commerce,o-failed,1', $tuples);
        $this->assertContains('w-user-1,t-core,p-site-readonly,o-refused,1', $tuples);
        $this->assertContains('w-user-2,t-woocommerce,p-commerce,o-waiting,1', $tuples);
        $this->assertContains('w-user-1,t-woocommerce,p-commerce,o-approved,1', $tuples);
        $this->assertSame($map['total'], array_sum(array_map(static fn (string $t): int => (int) substr($t, (int) strrpos($t, ',') + 1), $tuples)), 'tuples sum to the total');

        $c = self::counts($map);
        $this->assertSame(['o-ran' => 2, 'o-approved' => 1, 'o-waiting' => 1, 'o-refused' => 1, 'o-failed' => 1], $c['outcome']);
        $labels = array_column($map['lanes']['through'], 'label', 'id');
        $this->assertSame('Site Agent', $labels['t-specflux-site-agent']);
        $this->assertSame('WooCommerce', $labels['t-woocommerce']);
        $this->assertSame('WordPress', $labels['t-core']);
    }

    public function testTupleIdsAreSafeForTheKitSeparators(): void
    {
        $this->row('allowed', 'success', 'weird,ns;x|y/do', 'app:uuid-claude', 'Pack, One; two');

        $map = $this->map();

        foreach (explode(';', $map['tuples']) as $tuple) {
            $this->assertMatchesRegularExpression('/^[wtp]-[a-z0-9-]+,[wtp]-[a-z0-9-]+,[wtp]-[a-z0-9-]+,o-[a-z]+,\d+$/', $tuple);
        }
    }

    public function testApplicationPasswordsShowOnlyTheirNameAndNeverTheUuid(): void
    {
        $this->row('allowed', 'success', 'core/get-site-info', 'app:uuid-claude');
        $this->row('allowed', 'success', 'core/get-site-info', 'app:uuid-claude2');
        $this->row('allowed', 'success', 'core/get-site-info', 'app:uuid-removed');

        $map = $this->map();

        // What is drawn (labels, ids, tuples) never carries the identifier. Only the click-through
        // filter does, an id that is no credential and that the audit table already shows administrators.
        $drawn = (string) json_encode([$map['tuples'], array_column($map['lanes']['who'], 'label'), array_column($map['lanes']['who'], 'id')]);
        $this->assertStringNotContainsString('uuid-', $drawn);
        $labels = array_column($map['lanes']['who'], 'label', 'id');
        $this->assertSame('Claude', $labels['w-app-claude'], 'two passwords with one name are one node');
        $this->assertSame('Application password (removed)', $labels['w-app-application-password-removed']);
        $claude = array_values(array_filter($map['lanes']['who'], static fn (array $n): bool => $n['id'] === 'w-app-claude'))[0];
        $this->assertSame(2, $claude['count']);
        $this->assertSame('AI app', $claude['sub']);
        $this->assertSame('token', $claude['filter']['key']);
    }

    public function testFewUsersAreNamedManyCollapseIntoLoggedInPeople(): void
    {
        foreach ([1, 2, 3] as $id) {
            $this->row('allowed', 'success', 'core/get-site-info', 'user:' . $id);
        }
        $labels = array_column($this->map()['lanes']['who'], 'label');
        sort($labels);
        $this->assertSame(['Dana', 'Lee', 'Stephen'], $labels);

        $this->row('allowed', 'success', 'core/get-site-info', 'user:4');
        $map = $this->map();
        $this->assertSame(['Logged-in people'], array_column($map['lanes']['who'], 'label'));
        $this->assertSame(4, $map['lanes']['who'][0]['count']);
        $this->assertSame(['key' => 'token_prefix', 'value' => 'user:'], $map['lanes']['who'][0]['filter']);
    }

    public function testWooCommerceKeysShowOnlyTheLastFourCharacters(): void
    {
        $this->row('allowed', 'success', 'woocommerce/orders-list', 'wc:7', 'commerce');

        $labels = array_column($this->map()['lanes']['who'], 'label');

        $this->assertSame(['WooCommerce key ...1234'], $labels);
        $this->assertStringNotContainsString('ck_abcd', (string) json_encode($this->map()));
    }

    public function testAConnectorCanNameItsOwnIdentitiesThroughTheFilter(): void
    {
        $this->row('allowed', 'success', 'core/get-site-info', 'oauth:abc123');
        add_filter('belayline_map_who', static fn ($named, string $token) => str_starts_with($token, 'oauth:') ? ['label' => 'Claude (connector)', 'sub' => 'AI app'] : $named, 10, 2);

        $this->assertSame(['Claude (connector)'], array_column($this->map()['lanes']['who'], 'label'));
    }

    public function testAnUnknownTokenShapeAndNoTokenAreLabelledNotLeaked(): void
    {
        $this->row('allowed', 'success', 'core/get-site-info', 'mystery:secret-value');
        $this->row('allowed', 'success', 'core/get-site-info', null);

        $labels = array_column($this->map()['lanes']['who'], 'label');
        sort($labels);

        $this->assertSame(['Mystery', 'Unknown caller'], $labels);
    }

    public function testTheFirstTokenInTheRecordWinsSoAgentInputCannotImpersonate(): void
    {
        $this->row('allowed', 'success', 'core/get-site-info', 'app:uuid-gpt', 'content', 0, ['token_id' => 'x', 'nested' => ['token_id' => 'user:1"']]);
        $this->row('allowed', 'success', 'core/get-site-info', 'app:uuid-gpt', 'content', 0, ['note' => 'a "token_id":"user:1" lookalike']);

        $map = $this->map();

        $this->assertSame(['ChatGPT'], array_column($map['lanes']['who'], 'label'));
        $this->assertSame(2, $map['lanes']['who'][0]['count']);
    }

    /** Regression: with no actor token, the first `"token_id":"` in the record was an agent's own input key. */
    public function testARowWithNoTokenCannotBeClaimedByATokenIdKeyInAgentInput(): void
    {
        $this->row('allowed', 'success', 'core/get-site-info', null, 'content', 0, ['token_id' => 'app:uuid-gpt']);
        $this->row('allowed', 'success', 'core/get-site-info', null, 'content', 0, ['actor' => ['token_id' => 'app:uuid-gpt']]);

        $map = $this->map();

        $this->assertSame(['Unknown caller'], array_column($map['lanes']['who'], 'label'));
        $this->assertSame(2, $map['lanes']['who'][0]['count']);
    }

    public function testAnAiAppThatRodeABoundRoleIsDrawnAsTheApp(): void
    {
        add_filter('belayline_map_who', static fn ($named, string $token) => str_starts_with($token, 'oauth:') ? ['label' => 'Claude (connector)', 'sub' => 'AI app'] : $named, 10, 2);
        $this->row('allowed', 'success', 'core/get-site-info', 'role:administrator', 'content', 0, [], null, false, 'oauth:abc123');

        $this->assertSame(['Claude (connector)'], array_column($this->map()['lanes']['who'], 'label'));
    }

    public function testAViaKeyInAgentInputIsNotTheRowsAgentCredential(): void
    {
        add_filter('belayline_map_who', static fn ($named, string $token) => str_starts_with($token, 'oauth:') ? ['label' => 'Claude (connector)', 'sub' => 'AI app'] : $named, 10, 2);
        $this->row('allowed', 'success', 'core/get-site-info', 'app:uuid-gpt', 'content', 0, ['a' => 1, 'via' => 'oauth:abc123']);

        $this->assertSame(['ChatGPT'], array_column($this->map()['lanes']['who'], 'label'));
    }

    public function testLanesAreCappedAtSixWithTheTailFoldedIntoMore(): void
    {
        foreach (range(1, 9) as $i) {
            // Distinct namespaces, with a descending call count so the fold is deterministic.
            for ($n = 0; $n < 10 - $i; $n++) {
                $this->row('allowed', 'success', "plug{$i}/do", 'app:uuid-claude', "pack{$i}");
            }
        }

        $map = $this->map();

        foreach (['through', 'pack'] as $lane) {
            $this->assertCount(MapData::LANE_MAX, $map['lanes'][$lane], $lane . ' lane is capped');
            $last = $map['lanes'][$lane][MapData::LANE_MAX - 1];
            $this->assertSame(substr($lane, 0, 1) . '-more', $last['id']);
            $this->assertSame('+4 more', $last['label']);
            $this->assertNull($last['filter'], 'a folded box cannot be filtered to');
            // 9+8+7+6+5 kept, 4+3+2+1 folded.
            $this->assertSame(10, $last['count']);
        }
        $this->assertSame(45, $map['total']);
        $this->assertSame(45, array_sum(array_map(static fn (string $t): int => (int) substr($t, (int) strrpos($t, ',') + 1), explode(';', $map['tuples']))));
    }

    public function testOnlyTheLastSevenDaysCount(): void
    {
        $this->row('allowed', 'success', 'core/get-site-info', 'app:uuid-claude', 'content', 0);
        $this->row('allowed', 'success', 'core/get-site-info', 'app:uuid-claude', 'content', 6);
        $this->row('allowed', 'success', 'core/get-site-info', 'app:uuid-claude', 'content', 8);

        $this->assertSame(2, $this->map()['total']);
    }

    public function testTheMapQueryIsOneGroupByOverATsRange(): void
    {
        $this->row('allowed', 'success', 'core/get-site-info', 'app:uuid-claude');
        $this->db->queries = [];

        (new MapData($this->db, $this->resolver()))->pathRows('2027-01-08T00:00:00Z');

        $this->assertCount(1, $this->db->queries);
        $sql = $this->db->queries[0];
        $this->assertStringContainsString('FROM `wp_belayline_audit_log`', $sql);
        $this->assertStringContainsString("WHERE l.ts >= '2027-01-08T00:00:00Z'", $sql);
        $this->assertStringContainsString('GROUP BY who, ns, pack, outcome', $sql);
        $this->assertStringContainsString("l.decision IN ('allowed', 'denied', 'pending')", $sql);
        $this->assertStringContainsString('LEFT JOIN `wp_belayline_approvals` a ON a.audit_event_id = l.event_id', $sql);
        $this->assertStringContainsString("HAVING outcome <> ''", $sql);
        $this->assertSame(1, substr_count($sql, 'GROUP BY'));
    }

    public function testDailySeriesCoversSevenDaysOldestFirst(): void
    {
        $this->row('allowed', 'success', 'core/get-site-info', 'app:uuid-claude', 'content', 0);
        $this->row('allowed', 'success', 'core/get-site-info', 'app:uuid-claude', 'content', 0);
        $this->row('denied', null, 'core/get-site-info', 'app:uuid-claude', 'content', 0);
        $this->row('pending', null, 'core/get-site-info', 'user:1', 'content', 1, [], 'rejected');
        $this->row('pending', null, 'core/get-site-info', 'user:1', 'content', 1, [], 'pending');
        $this->row('approved', null, 'core/get-site-info', 'user:1', 'content', 1);

        $days = $this->map()['days'];

        $this->assertCount(7, $days);
        $this->assertSame(gmdate('Y-m-d', self::NOW), $days[6]['date']);
        $this->assertSame(['calls' => 3, 'refused' => 1], ['calls' => $days[6]['calls'], 'refused' => $days[6]['refused']]);
        $this->assertSame(['calls' => 2, 'refused' => 1], ['calls' => $days[5]['calls'], 'refused' => $days[5]['refused']], 'a rejected request is one refused call, a waiting one is a call, the approved decision row is neither');
        $this->assertSame(0, $days[0]['calls']);
    }

    public function testStatCardDaysCountCallsByTheSameRulesAsTheMap(): void
    {
        $ts = gmdate('Y-m-d\TH:i:s\Z', self::NOW - 60);
        $actor = ['token_id' => 'user:1', 'wp_user' => 1];
        // One approved call: request row + decision row + the execution that names its approval = ONE call.
        $this->row('pending', null, 'woocommerce/order-refund', 'user:1', 'commerce', 0, [], 'consumed');
        $this->row('approved', null, 'woocommerce/order-refund', 'user:1', 'commerce');
        $this->sink->append(AuditRecord::execution('evt_exec_a', $ts, 'corr_exec_a', 'commerce', $actor, 'woocommerce/order-refund', 2, [], 'success', approvalId: 'apr_' . 1));
        // Two plain calls, one refusal, one shadow-mode observation (not a call; its run is the call).
        $this->row('allowed', 'success', 'core/get-site-info', 'user:1');
        $this->row('allowed', 'success', 'core/get-site-info', 'user:1');
        $this->row('denied', null, 'core/get-site-info', 'user:1');
        $this->row('denied', null, 'core/get-site-info', 'user:1', 'content', 0, [], null, true);

        $map = $this->map();
        $today = $map['days'][6];

        $this->assertSame(4, $map['total'], 'approved(1) + ran(2) + refused(1); decision rows and the shadow observation are not calls');
        $this->assertSame(4, $today['calls'], 'the Calls today card equals the map total, not the 7 raw rows');
        $this->assertSame(1, $today['refused'], 'the Refused today card counts refused calls only, not shadow observations');
    }

    public function testTheResultIsCachedForFiveMinutesAndRebuiltWhenAnAuditRowAppears(): void
    {
        $this->row('allowed', 'success', 'core/get-site-info', 'app:uuid-claude');
        $data = new MapData($this->db, $this->resolver());

        $first = $data->get(self::NOW);
        $this->assertSame(1, $first['total']);
        $this->assertArrayHasKey(MapData::CACHE_KEY, $GLOBALS['wpas_test_transients']);

        // Same newest id, inside the TTL: served from the cache, no GROUP BY query runs.
        $this->db->queries = [];
        $this->assertSame($first, $data->get(self::NOW + 120));
        $this->assertCount(1, $this->db->queries, 'only the cheap MAX(id) probe');
        $this->assertStringContainsString('MAX(id)', $this->db->queries[0]);

        // A new audit row invalidates it immediately.
        $this->row('denied', null, 'core/get-site-info', 'app:uuid-claude');
        $this->assertSame(2, $data->get(self::NOW + 130)['total']);

        // Past five minutes it is rebuilt even when nothing was appended.
        $this->db->queries = [];
        $data->get(self::NOW + 130 + MapData::TTL + 1);
        $this->assertGreaterThan(1, count($this->db->queries));
    }

    public function testAnEmptyLogGivesAnEmptyMapWithAllFiveOutcomeBoxes(): void
    {
        $map = $this->map();

        $this->assertSame(0, $map['total']);
        $this->assertSame('', $map['tuples']);
        $this->assertSame(['o-ran', 'o-approved', 'o-waiting', 'o-refused', 'o-failed'], array_column($map['lanes']['outcome'], 'id'));
        $this->assertSame([], $map['lanes']['who']);
    }
}
