<?php

declare(strict_types=1);

namespace Belayline\Plugin\Audit;

use Belayline\Plugin\Support\Schema;
use wpdb;

/**
 * Deletes audit rows older than a retention window without breaking the hash
 * chain. Rows are removed oldest-first in batches of {@see BATCH}; before each
 * batch is deleted, a checkpoint naming its last row (id + entry_hash) is
 * written, so {@see AuditReader::verifyChain()} can start from that hash
 * instead of GENESIS.
 *
 * Checkpoint-then-delete, both inside the same advisory lock the appender uses
 * ({@see WpdbAuditSink::tryAppendLock()}), so a concurrent append can neither
 * read a half-pruned head nor race the delete. If the process dies between the
 * two steps the rows at or below the checkpointed id simply linger; the
 * verifier ignores them and the next run deletes them.
 *
 * Only a contiguous prefix by id is ever deleted (it stops at the first row
 * newer than the cutoff), so a skewed timestamp can never punch a hole in the
 * middle of the chain.
 */
final class AuditPruner
{
    public const BATCH = 500;

    /** Bounds one cron run to 100k rows; the next daily run continues. */
    public const MAX_BATCHES_PER_RUN = 200;

    public function __construct(
        private readonly wpdb $db,
        private readonly WpdbAuditSink $sink,
    ) {
    }

    /** Prune rows older than $days days. Returns the number of rows deleted. */
    public function prune(int $days, ?int $now = null): int
    {
        if ($days < 1) {
            return 0;
        }

        $cutoff = gmdate('Y-m-d\TH:i:s\Z', ($now ?? time()) - $days * 86400);
        $total = 0;
        for ($i = 0; $i < self::MAX_BATCHES_PER_RUN; ++$i) {
            $deleted = $this->pruneBatch($cutoff);
            $total += $deleted;
            if ($deleted < self::BATCH) {
                break;
            }
        }

        return $total;
    }

    /** One locked checkpoint-then-delete round. Returns rows deleted (0 when nothing is due or the lock is busy). */
    private function pruneBatch(string $cutoff): int
    {
        if (!$this->sink->tryAppendLock()) {
            return 0;
        }

        try {
            $rows = $this->db->get_results(
                $this->db->prepare('SELECT id, ts, entry_hash FROM %i ORDER BY id ASC LIMIT %d', $this->sink->table(), self::BATCH),
                ARRAY_A
            );

            $lastId = 0;
            $lastHash = '';
            $count = 0;
            foreach (is_array($rows) ? $rows : [] as $row) {
                if ((string) ($row['ts'] ?? '') >= $cutoff) {
                    break; // first row inside the window: everything after it stays.
                }
                $lastId = (int) $row['id'];
                $lastHash = (string) $row['entry_hash'];
                ++$count;
            }
            if ($count === 0) {
                return 0;
            }

            $this->writeCheckpoint($lastId, $lastHash, $count);

            $deleted = $this->db->query(
                // phpcs:ignore WordPress.DB.PreparedSQL -- trusted internal table name and an int id.
                sprintf('DELETE FROM `%s` WHERE id <= %d', $this->sink->table(), $lastId)
            );

            return is_int($deleted) ? $deleted : 0;
        } finally {
            $this->sink->releaseAppendLock();
        }
    }

    private function writeCheckpoint(int $throughId, string $throughHash, int $batchCount): void
    {
        $table = Schema::checkpointTable($this->db);
        $this->db->query(
            "CREATE TABLE IF NOT EXISTS {$table} (\n" . Schema::checkpointColumns() . "\n) " . $this->db->get_charset_collate()
        );

        $existing = $this->db->get_row(
            $this->db->prepare('SELECT id, pruned_count FROM %i ORDER BY id DESC LIMIT 1', $table),
            ARRAY_A
        );

        if (is_array($existing) && isset($existing['id'])) {
            $this->db->update(
                $table,
                [
                    'created_ts' => gmdate('Y-m-d H:i:s'),
                    'pruned_through_id' => $throughId,
                    'pruned_through_entry_hash' => $throughHash,
                    'pruned_count' => (int) ($existing['pruned_count'] ?? 0) + $batchCount,
                ],
                ['id' => (int) $existing['id']],
                ['%s', '%d', '%s', '%d'],
                ['%d'],
            );

            return;
        }

        $this->db->insert(
            $table,
            [
                'created_ts' => gmdate('Y-m-d H:i:s'),
                'pruned_through_id' => $throughId,
                'pruned_through_entry_hash' => $throughHash,
                'pruned_count' => $batchCount,
            ],
            ['%s', '%d', '%s', '%d'],
        );
    }
}
