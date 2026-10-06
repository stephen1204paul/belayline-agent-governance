<?php

declare(strict_types=1);

namespace Specflux\AgentSafety\Changes;

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

    /** Record a change into the open window; ignored when none is open. */
    public function record(Change $change): void
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
        $this->eventId = '';

        return $closed;
    }
}
