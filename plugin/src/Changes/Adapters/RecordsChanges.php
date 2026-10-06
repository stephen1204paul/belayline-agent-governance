<?php

declare(strict_types=1);

namespace Specflux\AgentSafety\Plugin\Changes\Adapters;

use Specflux\AgentSafety\Changes\CaptureWindow;
use Specflux\AgentSafety\Changes\Change;
use Specflux\AgentSafety\Changes\ChangeAction;

/**
 * Shared plumbing for the built-in hook adapters: a bound window, a
 * record() that drops no-op updates, and a guard that never lets a listener
 * throw into the write it observes (a failure becomes a `capture_error`
 * marker change, never restorable).
 */
trait RecordsChanges
{
    private ?CaptureWindow $window = null;

    private bool $attached = false;

    /**
     * @param array<string, mixed>|null $before
     * @param array<string, mixed>|null $after
     */
    private function record(string $type, string $id, ?string $subtype, ChangeAction $action, ?array $before, ?array $after): void
    {
        if ($this->window === null) {
            return;
        }

        $change = Change::capture($type, $id, $subtype, $action, $before, $after, $this->window->redactsPii());
        if ($action === ChangeAction::Update && $change->changedFields === []) {
            return; // a write that changed nothing
        }

        $this->window->record($change);
    }

    /** Run a listener body only while a window is open, never letting it throw into the caller's write. */
    private function guard(string $kind, callable $body): void
    {
        if ($this->window === null || !$this->window->isOpen()) {
            return;
        }

        try {
            $body();
        } catch (\Throwable) {
            // The change could not be recorded; say so in the history instead
            // of leaving a silent gap.
            $this->window->record(Change::capture('window', 'capture_error', $kind, ChangeAction::Irreversible, null, ['listener' => $kind]));
        }
    }
}
