<?php

declare(strict_types=1);

namespace Belayline\Changes;

/** Persistence port for recorded changes (the host supplies the storage). */
interface ChangeLedger
{
    public function insertWindow(ClosedWindow $window, string $ts): void;

    /** @return list<StoredChange> Oldest first (recording order). */
    public function forEvent(string $eventId): array;

    /** @return list<StoredChange> Oldest first (recording order across events). */
    public function forCorrelation(string $correlationId): array;

    /**
     * Correlation ids with recorded changes, most recently written first.
     *
     * @return list<string>
     */
    public function recentCorrelations(int $limit, int $offset = 0): array;

    public function find(int $id): ?StoredChange;

    /** @param list<int> $ids */
    public function markRestored(array $ids, string $eventId): void;
}
