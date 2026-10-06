<?php

declare(strict_types=1);

namespace Specflux\AgentSafety\Plugin\Api;

use Specflux\AgentSafety\Changes\FieldDiff;
use Specflux\AgentSafety\Changes\StoredChange;

/** Read-only view of one recorded change, as the public API hands it to consumers. */
final class RecordedChange
{
    public function __construct(private readonly StoredChange $change)
    {
    }

    public function id(): int
    {
        return $this->change->id;
    }

    public function eventId(): string
    {
        return $this->change->eventId;
    }

    public function correlationId(): string
    {
        return $this->change->correlationId;
    }

    /** `post`, `post_meta`, `term`, `option`, ... */
    public function objectType(): string
    {
        return $this->change->objectType;
    }

    public function objectId(): string
    {
        return $this->change->objectId;
    }

    /** `create`, `update`, `delete`, `trash` or `irreversible`. */
    public function action(): string
    {
        return $this->change->action->value;
    }

    /** @return list<array{field: string, before: mixed, after: mixed}> */
    public function fieldDiff(): array
    {
        return FieldDiff::for($this->change);
    }

    /**
     * Whether an undo could ever restore this change, judged from what was
     * recorded. It does not look at live state: a restorable change may still
     * be refused as drifted, which only {@see Changes::preview()} shows.
     */
    public function restorable(): bool
    {
        return $this->change->refusal() === null;
    }

    /** Machine reason code when not restorable (`irreversible`, `truncated`, `already_restored`, ...), else null. */
    public function reason(): ?string
    {
        return $this->change->refusal();
    }

    /** The undo event that restored this change, or null. */
    public function restoredByEvent(): ?string
    {
        return $this->change->restoredByEvent;
    }

    /** UTC `Y-m-d H:i:s` the change was recorded, or null if unknown. */
    public function recordedAt(): ?string
    {
        return $this->change->ts;
    }
}
