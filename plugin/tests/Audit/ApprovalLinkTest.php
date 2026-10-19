<?php

declare(strict_types=1);

namespace Belayline\Plugin\Tests\Audit;

use Belayline\Audit\AuditDecision;
use Belayline\Audit\AuditRecord;
use Belayline\Plugin\Admin\Map\CallOutcome;
use Belayline\Plugin\Admin\Map\MapData;
use Belayline\Plugin\Admin\Map\WhoResolver;
use Belayline\Plugin\Audit\AuditFilter;
use Belayline\Plugin\Audit\AuditReader;
use Belayline\Plugin\Audit\WpdbAuditSink;
use Belayline\Plugin\Hooks\AbilityAuditLog;
use Belayline\Plugin\Hooks\McpRequestAuditHandler;
use Belayline\Plugin\Support\ApprovalLink;
use Belayline\Plugin\Support\PackResolver;
use Belayline\Plugin\Tests\Fakes\SqliteWpdb;
use Belayline\Policy\TierClassifier;
use PHPUnit\Framework\TestCase;

/**
 * An execution row names the approval it consumed (top-level `approval_id` in the
 * hashed record AND the indexed column), the chain cross-checks the two, and the
 * call counting uses the link so one approved call is one call.
 */
final class ApprovalLinkTest extends TestCase
{
    private const NOW = 1_800_000_000;

    private SqliteWpdb $db;
    private WpdbAuditSink $sink;
    private AuditReader $reader;
    private int $seq = 0;

    protected function setUp(): void
    {
        $GLOBALS['wpas_test_transients'] = [];
        $GLOBALS['wpas_test_time'] = self::NOW;
        McpRequestAuditHandler::reset();
        ApprovalLink::reset();
        $this->db = new SqliteWpdb();
        $this->sink = new WpdbAuditSink($this->db);
        $this->reader = new AuditReader($this->db);
    }

    protected function tearDown(): void
    {
        $GLOBALS['wpas_test_time'] = time();
        ApprovalLink::reset();
        McpRequestAuditHandler::reset();
    }

    private function ts(): string
    {
        return gmdate('Y-m-d\TH:i:s\Z', self::NOW - 60 - (1000 - $this->seq));
    }

    private function execution(string $result, ?string $approvalId, array $input = []): void
    {
        $id = 'evt_' . ++$this->seq;
        $this->sink->append(AuditRecord::execution($id, $this->ts(), 'corr_' . $this->seq, 'commerce', ['token_id' => 'user:1', 'wp_user' => 1], 'woocommerce/order-refund', 2, $input, $result, approvalId: $approvalId));
    }

    /** A pending request row plus the approvals row it filed. */
    private function request(string $approvalId, string $status): void
    {
        $id = 'evt_' . ++$this->seq;
        $this->sink->append(AuditRecord::decision($id, $this->ts(), 'corr_' . $this->seq, 'commerce', ['token_id' => 'user:1', 'wp_user' => 1], 'woocommerce/order-refund', 2, [], AuditDecision::Pending));
        $this->db->insert('wp_belayline_approvals', ['approval_id' => $approvalId, 'audit_event_id' => $id, 'status' => $status, 'verb' => 'woocommerce/order-refund']);
    }

    /** @return array<string, int> outcome id => calls */
    private function outcomes(): array
    {
        $map = (new MapData($this->db, new WhoResolver(static fn (): array => [], static fn (): ?string => null, static fn (array $ids): array => [])))->compute(self::NOW);
        $out = [];
        foreach ($map['lanes'] as $nodes) {
            foreach ($nodes as $n) {
                if (str_starts_with($n['id'], 'o-')) {
                    $out[substr($n['id'], 2)] = $n['count'];
                }
            }
        }

        return $out;
    }

    /** @return array<string, mixed> */
    private function lastRow(): array
    {
        return $this->db->get_results('SELECT * FROM wp_belayline_audit_log ORDER BY id DESC LIMIT 1', ARRAY_A)[0];
    }

    public function testAnExecutionAfterAnApprovalRecordsItsIdInTheHashedRecordAndTheColumn(): void
    {
        ApprovalLink::bind('woocommerce/order-refund', 'apr_77');
        $log = new AbilityAuditLog($this->sink, new TierClassifier(), new PackResolver(), ['woocommerce/']);
        $log->before('woocommerce/order-refund', ['order_id' => 5]);
        $log->after('woocommerce/order-refund', ['order_id' => 5], ['data' => ['status' => 200]]);

        $row = $this->lastRow();
        $this->assertSame('apr_77', $row['approval_id']);
        $record = json_decode($row['record_json'], true);
        $this->assertSame('apr_77', $record['approval_id']);
        $this->assertSame(['order_id' => 5], $record['input']);
        $this->assertTrue($this->reader->verifyChain());
        $this->assertNull(ApprovalLink::take('woocommerce/order-refund'), 'the link is consumed by the run it belongs to');
    }

    public function testARunThatUsedNoApprovalRecordsNone(): void
    {
        $log = new AbilityAuditLog($this->sink, new TierClassifier(), new PackResolver(), ['woocommerce/']);
        $log->before('woocommerce/orders-list', []);
        $log->after('woocommerce/orders-list', [], ['data' => ['status' => 200]]);

        $row = $this->lastRow();
        $this->assertNull($row['approval_id']);
        $this->assertArrayNotHasKey('approval_id', json_decode($row['record_json'], true));
    }

    public function testAnApprovalIdInAgentInputNeitherSetsTheColumnNorIsReadAsTheApprovalId(): void
    {
        $spoof = ['order_id' => 5, 'approval_id' => 'apr_FORGED'];
        $log = new AbilityAuditLog($this->sink, new TierClassifier(), new PackResolver(), ['woocommerce/']);

        // No approval was reserved: the forged key must not become the link.
        $log->before('woocommerce/order-refund', $spoof);
        $log->after('woocommerce/order-refund', $spoof, ['data' => ['status' => 200]]);
        $row = $this->lastRow();
        $this->assertNull($row['approval_id']);
        $record = json_decode($row['record_json'], true);
        $this->assertArrayNotHasKey('approval_id', $record, 'no top-level approval_id');
        $this->assertSame('apr_FORGED', $record['input']['approval_id'], 'the forged key stays inside the agent input');
        $this->assertSame(CallOutcome::RAN, CallOutcome::classify('allowed', 'success', $row['approval_id']), 'a plain run, not an approved one');
        $this->assertTrue($this->reader->verifyChain());

        // A real approval was reserved: the server id wins, the forged one stays in input.
        ApprovalLink::bind('woocommerce/order-refund', 'apr_REAL');
        $log->before('woocommerce/order-refund', $spoof);
        $log->after('woocommerce/order-refund', $spoof, ['data' => ['status' => 200]]);
        $row = $this->lastRow();
        $this->assertSame('apr_REAL', $row['approval_id']);
        $record = json_decode($row['record_json'], true);
        $this->assertSame('apr_REAL', $record['approval_id']);
        $this->assertSame('apr_FORGED', $record['input']['approval_id']);
        $this->assertTrue($this->reader->verifyChain());
    }

    public function testRowsWrittenBeforeTheChangeKeepTheirExactBytesAndStillVerifyAlongsideNewOnes(): void
    {
        $old = AuditRecord::execution('evt_old', '2027-01-15T07:00:00Z', 'corr_old', 'commerce', ['token_id' => 'user:1', 'wp_user' => 1], 'woocommerce/order-refund', 2, ['order_id' => 1], 'success');
        $this->assertSame(
            '{"id":"evt_old","ts":"2027-01-15T07:00:00Z","correlation_id":"corr_old","pack":"commerce","actor":{"token_id":"user:1","wp_user":1},"ability":"woocommerce/order-refund","tier":2,"input":{"order_id":1},"dry_run":false,"decision":"allowed","reason":null,"approval":null,"result":"success","external_effects":[],"ip":null}',
            $old->canonicalJson(),
            'a record without an approval id hashes exactly as it did before'
        );
        $this->sink->append($old);
        $this->execution('success', 'apr_1');
        $this->execution('success', null);

        $this->assertTrue($this->reader->verifyChain(), 'mixed old and new rows verify');
        $this->assertNull($this->db->get_var("SELECT approval_id FROM wp_belayline_audit_log WHERE event_id = 'evt_old'"));
    }

    public function testAColumnThatDisagreesWithTheHashedRecordIsReportedAsTampered(): void
    {
        $this->execution('success', null);
        $this->execution('success', 'apr_1');
        $this->assertTrue($this->reader->verifyChain());

        $this->db->query("UPDATE wp_belayline_audit_log SET approval_id = 'apr_2' WHERE event_id = 'evt_2'");
        $this->assertFalse($this->reader->verifyChain(), 'column changed to another id');

        $this->db->query("UPDATE wp_belayline_audit_log SET approval_id = NULL WHERE event_id = 'evt_2'");
        $this->assertFalse($this->reader->verifyChain(), 'column cleared on a row whose record names an approval');

        $this->db->query("UPDATE wp_belayline_audit_log SET approval_id = 'apr_1' WHERE event_id = 'evt_2'");
        $this->assertTrue($this->reader->verifyChain(), 'restored');

        $this->db->query("UPDATE wp_belayline_audit_log SET approval_id = 'apr_9' WHERE event_id = 'evt_1'");
        $this->assertFalse($this->reader->verifyChain(), 'column set on a row whose record names none');
    }

    public function testAnAgentInputKeyDoesNotMakeAHonestNullColumnLookTampered(): void
    {
        $this->execution('success', null, ['approval_id' => 'apr_FORGED']);
        $this->assertTrue($this->reader->verifyChain(), 'only the top-level key is compared');
    }

    public function testApprovedThenRanIsOneApprovedCall(): void
    {
        $this->request('apr_1', 'consumed');
        $this->execution('success', 'apr_1');

        $this->assertSame(1, $this->outcomes()['approved']);
        $this->assertSame(0, $this->outcomes()['ran']);
    }

    public function testApprovedThenFailedIsOneFailedCallNotTwo(): void
    {
        // A failed run rolls the approval back to `approved`.
        $this->request('apr_1', 'approved');
        $this->execution('failure', 'apr_1');

        $o = $this->outcomes();
        $this->assertSame(1, $o['failed']);
        $this->assertSame(0, $o['approved'], 'the request is not also counted as Approved');
        $this->assertSame(1, array_sum($o));
    }

    public function testApprovedFailedRetriedThenRanIsOneApprovedCall(): void
    {
        $this->request('apr_1', 'consumed');
        $this->execution('failure', 'apr_1');
        $this->execution('success', 'apr_1');

        $o = $this->outcomes();
        $this->assertSame(1, $o['approved']);
        $this->assertSame(0, $o['failed']);
        $this->assertSame(1, array_sum($o));
    }

    public function testASuccessFollowedByNothingElseStaysTheLatestAndAnApprovedCallStillWaitingIsNotAffected(): void
    {
        $this->request('apr_1', 'approved'); // approved, never run
        $this->request('apr_2', 'pending');

        $o = $this->outcomes();
        $this->assertSame(1, $o['approved']);
        $this->assertSame(1, $o['waiting']);
    }

    public function testAPlainAllowedRunIsStillRanAndOldRowsClassifyAsBefore(): void
    {
        $this->execution('success', null);
        $this->execution('failure', null);
        $this->request('apr_old', 'consumed'); // pre-change: the run carried no id, the consumed request is not counted

        $o = $this->outcomes();
        $this->assertSame(1, $o['ran']);
        $this->assertSame(1, $o['failed']);
        $this->assertSame(0, $o['approved']);
        $this->assertSame(2, array_sum($o));
    }

    public function testTheActivityOutcomeFilterSharesTheRules(): void
    {
        $this->request('apr_1', 'approved');
        $this->execution('failure', 'apr_1');
        $this->request('apr_2', 'consumed');
        $this->execution('failure', 'apr_2');
        $this->execution('success', 'apr_2');
        $this->execution('success', null);

        $count = fn (string $outcome): int => $this->reader->countFiltered(new AuditFilter(outcome: $outcome), self::NOW);
        $this->assertSame(1, $count('failed'));
        $this->assertSame(1, $count('approved'));
        $this->assertSame(1, $count('ran'));
        $this->assertSame(0, $count('waiting'));
    }

    public function testClassifyTwinNamesApprovedRuns(): void
    {
        $this->assertSame(CallOutcome::APPROVED, CallOutcome::classify('allowed', 'success', 'apr_1'));
        $this->assertSame(CallOutcome::FAILED, CallOutcome::classify('allowed', 'failure', 'apr_1'));
        $this->assertSame(CallOutcome::RAN, CallOutcome::classify('allowed', 'success', null));
        $this->assertSame(CallOutcome::RAN, CallOutcome::classify('allowed', 'success', ''));
    }
}
