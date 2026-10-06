<?php

declare(strict_types=1);

namespace Specflux\AgentSafety\Changes;

/** A persisted change as the undo engine reads it back, bodies decoded. */
final class StoredChange
{
    public const REASON_ALREADY_RESTORED = 'already_restored';
    public const REASON_NOT_RESTORABLE = 'not_restorable';

    /**
     * @param array<string, mixed>|null $before
     * @param array<string, mixed>|null $after
     * @param list<string>              $changedFields
     */
    public function __construct(
        public readonly int $id,
        public readonly string $eventId,
        public readonly string $correlationId,
        public readonly int $seq,
        public readonly string $objectType,
        public readonly string $objectId,
        public readonly ?string $objectSubtype,
        public readonly ChangeAction $action,
        public readonly ?array $before,
        public readonly ?array $after,
        public readonly array $changedFields,
        public readonly bool $restorable,
        public readonly ?string $reason,
        public readonly ?string $restoredByEvent,
    ) {
    }

    /**
     * Adapter-independent refusal: stored flags and markers that make a change
     * permanently un-undoable. Null when none applies.
     */
    public function refusal(): ?string
    {
        if ($this->restoredByEvent !== null) {
            return self::REASON_ALREADY_RESTORED;
        }
        if ($this->action === ChangeAction::Irreversible) {
            return Change::REASON_IRREVERSIBLE;
        }
        if (isset($this->before['_truncated']) || isset($this->after['_truncated'])) {
            return Change::REASON_TRUNCATED;
        }
        if (!$this->restorable) {
            return $this->reason ?? self::REASON_NOT_RESTORABLE;
        }

        return null;
    }
}
