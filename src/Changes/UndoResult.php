<?php

declare(strict_types=1);

namespace Belayline\Changes;

/** What an undo did. `items` are in the order they were processed (reverse recording order). */
final class UndoResult
{
    /** Everything restorable was restored. */
    public const COMPLETE = 'complete';
    /** Some changes were restored, then one failed and the rest were not attempted. */
    public const PARTIAL = 'partial';
    /** Nothing was restored. */
    public const REFUSED = 'refused';
    /** The user lacks a capability for part of the scope, or the call context is not a human's. Nothing written. */
    public const FORBIDDEN = 'forbidden';
    /** No recorded changes in scope. */
    public const EMPTY = 'empty';

    public const REASON_USER_MISMATCH = 'user_mismatch';
    public const REASON_AGENT_CONTEXT = 'agent_context';
    public const REASON_INSIDE_WINDOW = 'inside_governed_call';
    public const REASON_CAPABILITY = 'capability';

    /** @param list<UndoItem> $items */
    public function __construct(
        public readonly string $status,
        public readonly array $items = [],
        public readonly ?string $eventId = null,
        public readonly ?string $reason = null,
    ) {
    }

    /** @return list<UndoItem> */
    public function restored(): array
    {
        return array_values(array_filter($this->items, static fn (UndoItem $i): bool => $i->status === UndoItem::RESTORED));
    }
}
