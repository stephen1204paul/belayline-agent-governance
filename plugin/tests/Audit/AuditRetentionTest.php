<?php

declare(strict_types=1);

namespace Belayline\Plugin\Tests\Audit;

use Belayline\Audit\AuditDecision;
use Belayline\Audit\AuditRecord;
use Belayline\Audit\HashChain;
use Belayline\Plugin\Audit\AuditExporter;
use Belayline\Plugin\Audit\AuditPruner;
use Belayline\Plugin\Audit\AuditReader;
use Belayline\Plugin\Audit\WpdbAuditSink;
use Belayline\Plugin\Support\AuditRetention;
use Belayline\Plugin\Tests\Fakes\SqliteWpdb;
use PHPUnit\Framework\TestCase;

/**
 * Audit retention end to end against a real (SQLite) table: the pruner, the
 * checkpoint it writes, chain verification from that checkpoint, and the
 * JSON Lines export. Rows are appended through the real WpdbAuditSink so the
 * hashes are the production ones.
 */
final class AuditRetentionTest extends TestCase
{
    private SqliteWpdb $db;
    private WpdbAuditSink $sink;
    private AuditReader $reader;
    private AuditPruner $pruner;
    private int $seq = 0;

    protected function setUp(): void
    {
        $GLOBALS['wpas_test_options'] = [];
        $this->db = new SqliteWpdb();
        $this->sink = new WpdbAuditSink($this->db);
        $this->reader = new AuditReader($this->db);
        $this->pruner = new AuditPruner($this->db, $this->sink);
    }

    private function append(string $ts): void
    {
        $this->sink->append(AuditRecord::decision(
            id: 'evt_' . ++$this->seq,
            ts: $ts,
            correlationId: 'sess_1',
            pack: 'default-agent',
            actor: ['token_id' => 'ck_abc', 'wp_user' => 7],
            ability: 'demo/refund',
            tier: 2,
            input: ['order' => $this->seq],
            decision: AuditDecision::Allowed,
        ));
    }

    private function rowCount(): int
    {
        return (int) $this->db->get_var('SELECT COUNT(*) FROM wp_belayline_audit_log');
    }

    /** @return array<string, mixed> */
    private function checkpointRow(): array
    {
        $row = $this->db->get_row('SELECT * FROM wp_belayline_audit_checkpoint ORDER BY id DESC LIMIT 1');
        $this->assertIsArray($row, 'expected a checkpoint row');

        return $row;
    }

    private function deleteStatements(): int
    {
        return count(array_filter($this->db->queries, static fn (string $q): bool => str_starts_with($q, 'DELETE FROM')));
    }

    public function testRetentionOffDeletesNothingAndWritesNoCheckpoint(): void
    {
        $this->append(gmdate('Y-m-d\TH:i:s\Z', time() - 400 * 86400));
        $this->append(gmdate('Y-m-d\TH:i:s\Z'));

        $this->assertSame(0, AuditRetention::days());
        $this->assertSame(0, AuditRetention::run($this->pruner));

        $this->assertSame(2, $this->rowCount());
        $this->assertNull($this->reader->checkpoint());
        $this->assertTrue($this->reader->verifyChain());
    }

    public function testRetentionDaysDeletesOnlyOlderRowsInBatchesOfFiveHundredAndCheckpoints(): void
    {
        for ($i = 0; $i < 1100; ++$i) {
            $this->append(gmdate('Y-m-d\TH:i:s\Z', time() - 40 * 86400 + $i));
        }
        for ($i = 0; $i < 50; ++$i) {
            $this->append(gmdate('Y-m-d\TH:i:s\Z', time() - 86400 + $i));
        }
        $lastOldHash = (string) $this->db->get_var('SELECT entry_hash FROM wp_belayline_audit_log WHERE id = 1100');
        update_option(AuditRetention::OPTION, '30');

        $deleted = AuditRetention::run($this->pruner);

        $this->assertSame(1100, $deleted);
        $this->assertSame(50, $this->rowCount());
        $this->assertSame('1101', (string) $this->db->get_var('SELECT MIN(id) FROM wp_belayline_audit_log'));
        $this->assertSame(3, $this->deleteStatements(), '500 + 500 + 100');

        $checkpoint = $this->checkpointRow();
        $this->assertSame(1, (int) $this->db->get_var('SELECT COUNT(*) FROM wp_belayline_audit_checkpoint'), 'one checkpoint row, updated per batch');
        $this->assertSame(1100, (int) $checkpoint['pruned_through_id']);
        $this->assertSame(1100, (int) $checkpoint['pruned_count']);
        $this->assertSame($lastOldHash, $checkpoint['pruned_through_entry_hash']);
        $this->assertTrue($this->reader->verifyChain());
    }

    public function testNothingDueMeansNoCheckpointAndNoDelete(): void
    {
        $this->append(gmdate('Y-m-d\TH:i:s\Z'));

        $this->assertSame(0, $this->pruner->prune(30));

        $this->assertNull($this->reader->checkpoint());
        $this->assertSame(0, $this->deleteStatements());
    }

    public function testOnlyAContiguousPrefixIsDeletedEvenWithASkewedTimestamp(): void
    {
        $now = strtotime('2026-03-01 00:00:00 UTC');
        $this->append('2026-01-01T00:00:00Z');
        $this->append('2026-01-02T00:00:00Z');
        $this->append('2026-02-28T00:00:00Z'); // inside the window
        $this->append('2026-01-03T00:00:00Z'); // old, but behind a newer row: must stay

        $this->assertSame(2, $this->pruner->prune(30, $now));

        $this->assertSame(2, $this->rowCount());
        $this->assertTrue($this->reader->verifyChain());
    }

    public function testVerifyPassesBeforeAndAfterPruneAndFailsOnATamperedPostCheckpointRow(): void
    {
        for ($i = 1; $i <= 6; ++$i) {
            $this->append('2026-01-0' . $i . 'T00:00:00Z');
        }
        $this->assertTrue($this->reader->verifyChain());

        $this->pruner->prune(30, strtotime('2026-02-03 00:00:00 UTC')); // cutoff 01-04: rows 1..3 go
        $this->assertSame(3, $this->rowCount());
        $this->assertTrue($this->reader->verifyChain(), 'verifies from the checkpoint');

        $this->db->exec("UPDATE wp_belayline_audit_log SET record_json = REPLACE(record_json, 'demo/refund', 'demo/list') WHERE id = 5");
        $this->assertFalse($this->reader->verifyChain());
    }

    public function testVerifyFailsWhenTheCheckpointHashIsAltered(): void
    {
        for ($i = 1; $i <= 4; ++$i) {
            $this->append('2026-01-0' . $i . 'T00:00:00Z');
        }
        $this->pruner->prune(30, strtotime('2026-02-03 00:00:00 UTC'));
        $this->assertTrue($this->reader->verifyChain());

        $this->db->exec("UPDATE wp_belayline_audit_checkpoint SET pruned_through_entry_hash = '" . str_repeat('a', 64) . "'");

        $this->assertFalse($this->reader->verifyChain());
    }

    public function testVerifyFailsWhenTheFirstSurvivingRowIsRemovedOrRewrittenToMatchTheCheckpointLess(): void
    {
        for ($i = 1; $i <= 5; ++$i) {
            $this->append('2026-01-0' . $i . 'T00:00:00Z');
        }
        $this->pruner->prune(30, strtotime('2026-02-03 00:00:00 UTC')); // rows 1..2 pruned
        $this->db->exec('DELETE FROM wp_belayline_audit_log WHERE id = 4');

        $this->assertFalse($this->reader->verifyChain(), 'deleting the row right after the checkpoint breaks the join');
    }

    public function testRowsLeftBehindByAnInterruptedPruneAreIgnoredByTheVerifier(): void
    {
        for ($i = 1; $i <= 4; ++$i) {
            $this->append('2026-01-0' . $i . 'T00:00:00Z');
        }
        // Checkpoint written, delete never ran: rows 1..2 still exist.
        $hash = (string) $this->db->get_var('SELECT entry_hash FROM wp_belayline_audit_log WHERE id = 2');
        $this->db->insert('wp_belayline_audit_checkpoint', ['created_ts' => gmdate('Y-m-d H:i:s'), 'pruned_through_id' => 2, 'pruned_through_entry_hash' => $hash, 'pruned_count' => 2]);

        $this->assertTrue($this->reader->verifyChain());
    }

    public function testAppendAfterEveryRowWasPrunedContinuesFromTheCheckpoint(): void
    {
        $this->append('2026-01-01T00:00:00Z');
        $this->append('2026-01-02T00:00:00Z');
        $this->assertSame(2, $this->pruner->prune(30, strtotime('2026-06-01 00:00:00 UTC')));
        $this->assertSame(0, $this->rowCount());

        $this->append('2026-06-01T00:00:00Z');

        $first = $this->db->get_row('SELECT prev_hash FROM wp_belayline_audit_log ORDER BY id LIMIT 1');
        $this->assertIsArray($first);
        $this->assertSame($this->checkpointRow()['pruned_through_entry_hash'], $first['prev_hash']);
        $this->assertTrue($this->reader->verifyChain());
    }

    public function testPrunerSkipsWhenTheAppendLockIsBusy(): void
    {
        $this->append('2026-01-01T00:00:00Z');
        $busy = new \wpdb();
        $busy->varReturn = 0; // GET_LOCK timed out
        $pruner = new AuditPruner($busy, new WpdbAuditSink($busy));

        $this->assertSame(0, $pruner->prune(30, strtotime('2026-06-01 00:00:00 UTC')));
        $this->assertSame([], array_filter($busy->queries, static fn (string $q): bool => str_starts_with($q, 'DELETE')));
    }

    // --- export ---------------------------------------------------------------

    /** @return list<array<string, mixed>> */
    private function export(?string $from, ?string $to): array
    {
        $lines = [];
        (new AuditExporter($this->reader))->stream($from, $to, static function (string $line) use (&$lines): void {
            $lines[] = $line;
        });

        return array_map(static function (string $l): array {
            self::assertStringEndsWith("\n", $l);
            $decoded = json_decode($l, true);
            self::assertIsArray($decoded);

            return $decoded;
        }, $lines);
    }

    public function testExportEmitsTheCheckpointFirstThenRowsInIdOrder(): void
    {
        $this->append('2026-03-01T10:00:00Z');
        $this->append('2026-03-02T10:00:00Z');
        $this->append('2026-03-03T10:00:00Z');
        $this->pruner->prune(1, strtotime('2026-03-02 12:00:00 UTC')); // drops the 03-01 row only

        $out = $this->export(null, null);

        $this->assertSame(['checkpoint', 'entry', 'entry'], array_column($out, 'type'));
        $this->assertSame(1, $out[0]['pruned_through_id']);
        $this->assertSame(1, $out[0]['pruned_count']);
        $this->assertSame([2, 3], array_column(array_slice($out, 1), 'id'));
        $this->assertSame($out[0]['pruned_through_entry_hash'], $out[1]['prev_hash']);
    }

    /** @param list<array<string, mixed>> $lines */
    private function verifyExport(array $lines): bool
    {
        $anchor = HashChain::GENESIS;
        $entries = [];
        foreach ($lines as $l) {
            if ($l['type'] === 'checkpoint') {
                $anchor = $l['pruned_through_entry_hash'];
            } elseif ($l['type'] === 'range') {
                $anchor = $l['anchor_prev_hash'];
            } else {
                $entries[] = ['prev_hash' => $l['prev_hash'], 'canonical_json' => $l['canonical_json'], 'entry_hash' => $l['entry_hash']];
            }
        }

        return HashChain::verify($entries, $anchor);
    }

    public function testExportOfAPrunedLogVerifiesOfflineAndFailsWhenARecordIsEdited(): void
    {
        for ($i = 1; $i <= 5; ++$i) {
            $this->append('2026-03-0' . $i . 'T10:00:00Z');
        }
        $this->pruner->prune(1, strtotime('2026-03-03 12:00:00 UTC')); // rows 1..2 pruned

        $lines = $this->export(null, null);
        $this->assertSame(['checkpoint', 'entry', 'entry', 'entry'], array_column($lines, 'type'));
        $this->assertTrue($this->verifyExport($lines));

        $lines[2]['canonical_json'][10] = $lines[2]['canonical_json'][10] === 'x' ? 'y' : 'x';
        $this->assertFalse($this->verifyExport($lines));
    }

    public function testPartialRangeExportCarriesARangeAnchorAndVerifiesAsASegment(): void
    {
        foreach (['2026-03-01', '2026-03-02', '2026-03-03', '2026-03-04'] as $d) {
            $this->append($d . 'T10:00:00Z');
        }

        $lines = $this->export('2026-03-02', '2026-03-03');

        $this->assertSame(['range', 'entry', 'entry'], array_column($lines, 'type'));
        $this->assertSame($lines[1]['prev_hash'], $lines[0]['anchor_prev_hash']);
        $this->assertTrue($this->verifyExport($lines));
        $this->assertSame([], array_filter($this->export(null, null), static fn (array $l): bool => $l['type'] === 'range'), 'a full export needs no range line');
    }

    public function testExportRowsCarryEveryColumnWithTheRecordDecoded(): void
    {
        $this->append('2026-03-01T10:00:00Z');

        $out = $this->export(null, null);

        $this->assertCount(1, $out);
        $row = $out[0];
        foreach (['id', 'event_id', 'ts', 'correlation_id', 'pack', 'ability', 'tier', 'decision', 'result', 'wp_user', 'ip', 'record_json', 'canonical_json', 'record', 'prev_hash', 'entry_hash'] as $key) {
            $this->assertArrayHasKey($key, $row);
        }
        $this->assertSame(1, $row['id']);
        $this->assertSame(2, $row['tier']);
        $this->assertSame(7, $row['wp_user']);
        $this->assertSame('demo/refund', $row['record']['ability']);
        $this->assertSame(['order' => 1], $row['record']['input']);
    }

    public function testExportRespectsTheInclusiveDateRange(): void
    {
        foreach (['2026-03-01T23:59:59Z', '2026-03-02T00:00:00Z', '2026-03-02T23:59:59Z', '2026-03-03T00:00:00Z'] as $ts) {
            $this->append($ts);
        }

        $this->assertSame([2, 3], array_column($this->export('2026-03-02', '2026-03-02'), 'id'));
        $this->assertSame([2, 3, 4], array_column($this->export('2026-03-02', null), 'id'));
        $this->assertSame([1, 2, 3], array_column($this->export(null, '2026-03-02'), 'id'));
        $this->assertSame([], $this->export('2026-04-01', '2026-04-30'));
    }

    public function testExportStreamsInBatchesWithoutLosingOrDuplicatingRows(): void
    {
        for ($i = 0; $i < AuditExporter::BATCH + 25; ++$i) {
            $this->append('2026-03-01T10:00:00Z');
        }

        $ids = array_column($this->export(null, null), 'id');

        $this->assertSame(range(1, AuditExporter::BATCH + 25), $ids);
    }

    public function testNormalizeDateAcceptsOnlyRealCalendarDates(): void
    {
        $this->assertNull(AuditExporter::normalizeDate(''));
        $this->assertSame('2026-02-28', AuditExporter::normalizeDate('2026-02-28'));
        foreach (['2026-02-30', '28/02/2026', '2026-2-3', "2026-03-01\n; DROP"] as $bad) {
            try {
                AuditExporter::normalizeDate($bad);
                $this->fail("accepted {$bad}");
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testSanitizeDaysTreatsGarbageAsForeverAndCapsHugeValues(): void
    {
        $this->assertSame(0, AuditRetention::sanitizeDays('abc'));
        $this->assertSame(0, AuditRetention::sanitizeDays(['1']));
        $this->assertSame(30, AuditRetention::sanitizeDays('-30'));
        $this->assertSame(AuditRetention::MAX_DAYS, AuditRetention::sanitizeDays('99999999999'));
    }
}
