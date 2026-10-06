<?php

declare(strict_types=1);

namespace Specflux\AgentSafety\Plugin\Tests\Fakes;

use Specflux\AgentSafety\Changes\ChangeAction;
use Specflux\AgentSafety\Changes\ChangeLedger;
use Specflux\AgentSafety\Changes\ClosedWindow;
use Specflux\AgentSafety\Changes\StoredChange;

/** Hand-rolled ledger: stores exactly what {@see \Specflux\AgentSafety\Changes\Change::toRow()} hands the real table. */
final class InMemoryChangeLedger implements ChangeLedger
{
    /** @var list<array<string, mixed>> */
    public array $rows = [];

    public function insertWindow(ClosedWindow $window, string $ts): void
    {
        foreach ($window->changes as $i => $change) {
            $row = $change->toRow($i + 1);
            $row['id'] = count($this->rows) + 1;
            $row['event_id'] = $window->eventId;
            $row['correlation_id'] = $window->correlationId;
            $row['restored_by_event'] = null;
            $row['ts'] = $ts;
            $this->rows[] = $row;
        }
    }

    public function forEvent(string $eventId): array
    {
        return $this->select(static fn (array $r): bool => $r['event_id'] === $eventId);
    }

    public function forCorrelation(string $correlationId): array
    {
        return $this->select(static fn (array $r): bool => $r['correlation_id'] === $correlationId);
    }

    public function recentCorrelations(int $limit, int $offset = 0): array
    {
        $latest = [];
        foreach ($this->rows as $r) {
            $latest[(string) $r['correlation_id']] = $r['id'];
        }
        arsort($latest);

        return array_slice(array_map('strval', array_keys($latest)), $offset, $limit);
    }

    public function find(int $id): ?StoredChange
    {
        return $this->select(static fn (array $r): bool => $r['id'] === $id)[0] ?? null;
    }

    public function markRestored(array $ids, string $eventId): void
    {
        foreach ($this->rows as &$row) {
            if (in_array($row['id'], $ids, true) && $row['restored_by_event'] === null) {
                $row['restored_by_event'] = $eventId;
            }
        }
    }

    /** @param callable(array<string, mixed>): bool $filter @return list<StoredChange> */
    private function select(callable $filter): array
    {
        $out = [];
        foreach ($this->rows as $r) {
            if (!$filter($r)) {
                continue;
            }
            $out[] = new StoredChange(
                $r['id'],
                $r['event_id'],
                $r['correlation_id'],
                $r['seq'],
                $r['object_type'],
                $r['object_id'],
                $r['object_subtype'],
                ChangeAction::from($r['action']),
                $r['before_json'] === null ? null : json_decode($r['before_json'], true),
                $r['after_json'] === null ? null : json_decode($r['after_json'], true),
                json_decode($r['changed_fields'], true),
                $r['restorable'] === 1,
                $r['restorable_reason'],
                $r['restored_by_event'],
                $r['ts'] ?? null,
            );
        }

        return $out;
    }
}
