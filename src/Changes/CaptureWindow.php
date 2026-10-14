<?php

declare(strict_types=1);

namespace Belayline\Changes;

/**
 * The capture window around one governed call: pure state, no WordPress.
 *
 * Opens at the outermost governed call and closes when it returns. Windows
 * never nest: a governed call made while one is open joins it, and whatever it
 * changes is recorded under the OUTER event id. Every `open()` must be paired
 * with a `close()` by the caller (the host does so in a `finally`); a `close()`
 * with nothing open is a no-op rather than an error, so a double close can
 * never corrupt the next window.
 */
final class CaptureWindow
{
    /** Changes kept per window; past it one overflow marker is recorded and the rest dropped. */
    public const MAX_CHANGES = 500;

    private int $depth = 0;
    private string $eventId = '';
    private string $correlationId = '';
    private bool $redactPii = false;
    private bool $overflowed = false;

    /** @var array<string, true> Object keys a snapshotter owns; hook-recorded changes for them are dropped. */
    private array $claimed = [];

    /** @var list<Change> */
    private array $changes = [];

    /**
     * @return bool True when this call opened the window; false when it joined
     *              the already-open one (its event id and settings are ignored).
     */
    public function open(string $eventId, string $correlationId, bool $redactPii): bool
    {
        if ($this->depth > 0) {
            $this->depth++;

            return false;
        }

        $this->depth = 1;
        $this->eventId = $eventId;
        $this->correlationId = $correlationId;
        $this->redactPii = $redactPii;
        $this->overflowed = false;
        $this->changes = [];
        $this->claimed = [];

        return true;
    }

    public function isOpen(): bool
    {
        return $this->depth > 0;
    }

    public function eventId(): string
    {
        return $this->eventId;
    }

    public function redactsPii(): bool
    {
        return $this->redactPii;
    }

    /**
     * A snapshotter owns this object for the window: changes the hook
     * adapters record for it are dropped, so it is never recorded twice.
     * A claim made after the call (a result-aware snapshotter naming a
     * created object) also drops what hooks already recorded for it.
     */
    public function claim(string $objectType, string $objectId): void
    {
        $key = $objectType . "\0" . $objectId;
        $this->claimed[$key] = true;
        $this->changes = array_values(array_filter(
            $this->changes,
            static fn (Change $c): bool => $c->objectType . "\0" . $c->objectId !== $key,
        ));
    }

    /** Record a hook-observed change into the open window; ignored when none is open or a snapshotter claimed the object. */
    public function record(Change $change): void
    {
        if (isset($this->claimed[$change->objectType . "\0" . $change->objectId])) {
            return;
        }

        $this->recordSnapshot($change);
    }

    /** Record a snapshot-derived change (ignores claims: the snapshotter is the owner). */
    public function recordSnapshot(Change $change): void
    {
        if ($this->depth === 0) {
            return;
        }

        if (count($this->changes) >= self::MAX_CHANGES) {
            if (!$this->overflowed) {
                $this->overflowed = true;
                $this->changes[] = Change::capture(
                    'window',
                    'overflow',
                    null,
                    ChangeAction::Irreversible,
                    null,
                    ['dropped_after' => self::MAX_CHANGES],
                );
            }

            return;
        }

        $this->changes[] = $change;
    }

    /** @return ClosedWindow|null The finished window when this was the outermost close, else null. */
    public function close(): ?ClosedWindow
    {
        if ($this->depth === 0) {
            return null;
        }

        $this->depth--;

        return $this->depth === 0 ? $this->finish() : null;
    }

    /** Close regardless of depth (request shutdown with a call still open). */
    public function abort(): ?ClosedWindow
    {
        if ($this->depth === 0) {
            return null;
        }

        $this->depth = 0;

        return $this->finish();
    }

    private function finish(): ClosedWindow
    {
        $closed = new ClosedWindow($this->eventId, $this->correlationId, $this->changes);
        $this->changes = [];
        $this->claimed = [];
        $this->eventId = '';

        return $closed;
    }
}
