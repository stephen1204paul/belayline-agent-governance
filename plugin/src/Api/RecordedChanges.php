<?php

declare(strict_types=1);

namespace Belayline\Plugin\Api;

use Belayline\Changes\UndoScope;

/**
 * The changes recorded for one audit event or one correlation id (run), in
 * recording order. Feed {@see scope()} to {@see Changes::preview()} or
 * {@see Changes::undo()}.
 *
 * @implements \IteratorAggregate<int, RecordedChange>
 */
final class RecordedChanges implements \IteratorAggregate, \Countable
{
    /** @param list<RecordedChange> $changes */
    public function __construct(private readonly UndoScope $scope, private readonly array $changes)
    {
    }

    public function scope(): UndoScope
    {
        return $this->scope;
    }

    /** @return list<RecordedChange> */
    public function all(): array
    {
        return $this->changes;
    }

    /**
     * True when nothing was recorded. That is "no changes recorded", not proof
     * that nothing changed: raw SQL and unlisted object types are not captured.
     */
    public function isEmpty(): bool
    {
        return $this->changes === [];
    }

    public function count(): int
    {
        return count($this->changes);
    }

    /** @return \ArrayIterator<int, RecordedChange> */
    public function getIterator(): \ArrayIterator
    {
        return new \ArrayIterator($this->changes);
    }
}
