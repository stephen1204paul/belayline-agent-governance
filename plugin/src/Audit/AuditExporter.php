<?php

declare(strict_types=1);

namespace Belayline\Plugin\Audit;

use InvalidArgumentException;

/**
 * JSON Lines export of the audit log for a date range. One JSON object per line:
 * the latest retention checkpoint first (when the log has been pruned), then one
 * `entry` object per row in id order with every column, `record_json` decoded
 * into `record`. Rows are read in keyset batches and handed to the writer as
 * they come, so memory does not grow with the table.
 *
 * Decoding `record_json` is for readability: the chain hashes the stored bytes,
 * so re-verifying an export offline needs the original text, which the screen's
 * CSV export keeps.
 */
final class AuditExporter
{
    public const BATCH = 500;

    public function __construct(private readonly AuditReader $reader)
    {
    }

    /** Accepts '' / null (open end) or a real Y-m-d date; anything else throws. */
    public static function normalizeDate(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_string($value) && preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m) === 1 && checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            return $value;
        }

        throw new InvalidArgumentException('Dates must be real calendar dates in YYYY-MM-DD form.');
    }

    /**
     * Stream the export through $write (called once per line, newline included).
     * $from and $to are inclusive Y-m-d dates (UTC), either may be null.
     *
     * @param callable(string): void $write
     * @return int Number of audit rows written (the checkpoint line is not counted).
     */
    public function stream(?string $from, ?string $to, callable $write): int
    {
        $fromTs = $from ?? '';
        $beforeTs = $to !== null ? gmdate('Y-m-d', (int) strtotime($to . ' UTC') + 86400) : '9999-12-31';

        $checkpoint = $this->reader->checkpoint();
        if ($checkpoint !== null) {
            $write($this->line(['type' => 'checkpoint'] + $checkpoint));
        }

        $count = 0;
        $afterId = 0;
        do {
            $rows = $this->reader->after($afterId, self::BATCH, $fromTs, $beforeTs);
            foreach ($rows as $row) {
                $write($this->line($this->entry($row)));
                $afterId = (int) $row['id'];
                ++$count;
            }
        } while (count($rows) === self::BATCH);

        return $count;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function entry(array $row): array
    {
        $json = (string) ($row['record_json'] ?? '');
        $decoded = json_decode($json, true);

        $nullableInt = static fn (mixed $v): ?int => $v === null ? null : (int) $v;

        return [
            'type' => 'entry',
            'id' => (int) $row['id'],
            'event_id' => (string) ($row['event_id'] ?? ''),
            'ts' => (string) ($row['ts'] ?? ''),
            'correlation_id' => (string) ($row['correlation_id'] ?? ''),
            'pack' => (string) ($row['pack'] ?? ''),
            'ability' => (string) ($row['ability'] ?? ''),
            'tier' => $nullableInt($row['tier'] ?? null),
            'decision' => (string) ($row['decision'] ?? ''),
            'result' => $row['result'] ?? null,
            'wp_user' => $nullableInt($row['wp_user'] ?? null),
            'ip' => $row['ip'] ?? null,
            'record' => $decoded ?? $json,
            'prev_hash' => (string) ($row['prev_hash'] ?? ''),
            'entry_hash' => (string) ($row['entry_hash'] ?? ''),
        ];
    }

    /** @param array<string, mixed> $data */
    private function line(array $data): string
    {
        $encoded = wp_json_encode($data);

        return ($encoded === false ? '{"type":"error","message":"unencodable row"}' : $encoded) . "\n";
    }
}
