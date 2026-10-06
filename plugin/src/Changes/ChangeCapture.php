<?php

declare(strict_types=1);

namespace Specflux\AgentSafety\Plugin\Changes;

use Specflux\AgentSafety\Changes\CaptureWindow;
use Specflux\AgentSafety\Changes\ClosedWindow;
use Specflux\AgentSafety\Plugin\Support\PackResolver;
use Specflux\AgentSafety\Plugin\Support\RequestContext;
use Specflux\AgentSafety\Plugin\Verdict\VerdictPipeline;
use Specflux\AgentSafety\Policy\TierClassifier;

/**
 * Wraps a governed call's execution in a {@see CaptureWindow}: it opens for a
 * call at Tier >= 1, and closes when the call RETURNS, throws, or yields a
 * WP_Error. `wp_after_execute_ability` fires only on success, so closing is
 * done from a `finally` around the ability's own `execute_callback`
 * ({@see \Specflux\AgentSafety\Plugin\Hooks\AbilityPermissionGate::wrap()}) —
 * never from that hook — and a failing call can therefore never leak an open
 * window (and its listeners) into the rest of the request. A fatal skips
 * `finally`; {@see closeAll()} on shutdown covers that.
 *
 * Windows never nest: a governed call made inside another joins it, and its
 * changes are recorded under the OUTER event id. Only the outermost call
 * settles a window, so an inner call's audit record carries no
 * `changes_sha256` of its own.
 *
 * The event id is allocated when the window opens; the audit record that
 * follows the call must use it (via {@see takeSettled()}) so the change rows
 * join their audit row. Capturing never changes a call's outcome: storage
 * errors are swallowed, because the write has already happened and masking its
 * result would not undo it.
 */
final class ChangeCapture
{
    private static ?self $current = null;

    private readonly CaptureWindow $window;
    private readonly WpChangeListeners $listeners;

    /** Ability id of the outermost call that opened the current window. */
    private string $outer = '';

    /** @var array<string, list<array{event_id: string, changes_sha256: string}>> ability id => settled windows, newest last */
    private array $settled = [];

    public function __construct(
        private readonly TierClassifier $classifier,
        private readonly PackResolver $packs,
        private readonly WpdbChangeStore $store,
        ?CaptureWindow $window = null,
    ) {
        $this->window = $window ?? new CaptureWindow();
        $this->listeners = new WpChangeListeners($this->window);
    }

    /** The instance the audit writers consult; null when capture is not wired. */
    public static function current(): ?self
    {
        return self::$current;
    }

    public static function configure(?self $capture): void
    {
        self::$current = $capture;
    }

    public function register(): void
    {
        // Before AbilityAuditLog::flushFailures (priority 0) so a window left
        // open by a fatal is settled in time for the failure record to use.
        add_action('shutdown', [$this, 'closeAll'], -1);
    }

    /**
     * Run an ability's execution inside a window when its tier warrants one.
     *
     * @param array<string, mixed> $args
     * @param callable():mixed     $execute
     * @return mixed
     */
    public function run(string $ability, array $args, callable $execute)
    {
        if ($ability === VerdictPipeline::CHECK_APPROVAL_VERB) {
            return $execute();
        }

        $tier = $this->classifier->classify($ability, $args)?->value;
        if ($tier === null || $tier < 1) {
            return $execute();
        }

        $opened = $this->window->open(RequestContext::event(), RequestContext::correlation(), $this->packs->resolve()->redactsPii());
        if ($opened) {
            $this->outer = $ability;
        }

        try {
            if ($opened) {
                $this->listeners->attach();
            }

            return $execute();
        } finally {
            $this->settle($this->window->close());
        }
    }

    /** Settle a window still open at shutdown (a call that never returned). */
    public function closeAll(): void
    {
        $this->settle($this->window->abort());
    }

    /**
     * The window recorded for the most recent outermost call of this ability,
     * handed over once: the audit record for that call must carry its event id
     * and `changes_sha256`. Null when the call had no window (Tier 0, nested).
     *
     * @return array{event_id: string, changes_sha256: string}|null
     */
    public function takeSettled(string $ability): ?array
    {
        if (empty($this->settled[$ability])) {
            return null;
        }

        $entry = array_pop($this->settled[$ability]);
        if (empty($this->settled[$ability])) {
            unset($this->settled[$ability]);
        }

        return $entry;
    }

    private function settle(?ClosedWindow $closed): void
    {
        if ($closed === null) {
            return; // an inner call joining the outer window, or nothing open
        }

        $this->listeners->detach();

        try {
            $this->store->insertWindow($closed, gmdate('Y-m-d H:i:s'));
        } catch (\Throwable) {
            // Fail soft on storage only: see class docblock.
        }

        $this->settled[$this->outer][] = ['event_id' => $closed->eventId, 'changes_sha256' => $closed->sha256()];
    }
}
