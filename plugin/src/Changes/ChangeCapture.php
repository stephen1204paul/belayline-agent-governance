<?php

declare(strict_types=1);

namespace Belayline\Plugin\Changes;

use Belayline\Changes\CaptureWindow;
use Belayline\Changes\Change;
use Belayline\Changes\ChangeAction;
use Belayline\Changes\ChangeLedger;
use Belayline\Changes\ChangeSnapshotter;
use Belayline\Changes\ClosedWindow;
use Belayline\Changes\ResultAwareSnapshotter;
use Belayline\Changes\SnapshotRef;
use Belayline\Plugin\Support\PackResolver;
use Belayline\Plugin\Support\RequestContext;
use Belayline\Plugin\Verdict\VerdictPipeline;
use Belayline\Policy\TierClassifier;

/**
 * Wraps a governed call's execution in a {@see CaptureWindow}: it opens for a
 * call at Tier >= 1, and closes when the call RETURNS, throws, or yields a
 * WP_Error. `wp_after_execute_ability` fires only on success, so closing is
 * done from a `finally` around the ability's own `execute_callback`
 * ({@see \Belayline\Plugin\Hooks\AbilityPermissionGate::wrap()}) —
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
    private readonly ChangeAdapters $adapters;

    /** Ability id of the outermost call that opened the current window. */
    private string $outer = '';

    /** @var array<string, list<array{event_id: string, changes_sha256: string}>> ability id => settled windows, newest last */
    private array $settled = [];

    public function __construct(
        private readonly TierClassifier $classifier,
        private readonly PackResolver $packs,
        private readonly ChangeLedger $store,
        ?CaptureWindow $window = null,
        ?ChangeAdapters $adapters = null,
    ) {
        $this->window = $window ?? new CaptureWindow();
        $this->adapters = $adapters ?? new ChangeAdapters();
    }

    public function adapters(): ChangeAdapters
    {
        return $this->adapters;
    }

    public function isWindowOpen(): bool
    {
        return $this->window->isOpen();
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

        return $this->execute($ability, RequestContext::event(), RequestContext::correlation(), $execute, $args);
    }

    /**
     * Run `$execute` inside a window under a caller-chosen event id, with the
     * hook adapters attached (no snapshotters: there is no ability). Used by
     * the undo engine so an undo is recorded like any other change. Collect
     * the settled event with {@see takeSettled()} under `$label`.
     *
     * @param callable():mixed $execute
     * @return mixed
     */
    public function runWindow(string $label, string $eventId, string $correlationId, callable $execute)
    {
        return $this->execute($label, $eventId, $correlationId, $execute, null);
    }

    /**
     * @param callable():mixed          $execute
     * @param array<string, mixed>|null $args    Null = no snapshotters.
     * @return mixed
     */
    private function execute(string $label, string $eventId, string $correlationId, callable $execute, ?array $args)
    {
        $opened = $this->window->open($eventId, $correlationId, $this->packs->resolve()->redactsPii());
        if ($opened) {
            $this->outer = $label;
        }

        /** @var list<array{0: ChangeSnapshotter, 1: SnapshotRef, 2: array<string, mixed>|null}> $snaps */
        $snaps = [];
        $declared = [];
        $result = null;
        $returned = false;
        try {
            if ($opened) {
                foreach ($this->adapters->capture() as $adapter) {
                    $adapter->attach($this->window);
                }
                if ($args !== null) {
                    $snaps = $this->snapshotBefore($label, $args, $declared);
                }
            }

            $result = $execute();
            $returned = true;

            return $result;
        } finally {
            if ($opened && $snaps !== []) {
                $this->snapshotAfter($snaps);
            }
            if ($opened && $returned && $args !== null) {
                $this->snapshotCreated($label, $args, $result, $declared);
            }
            $this->settle($this->window->close());
        }
    }

    /**
     * Snapshot every ref a matching snapshotter declares, BEFORE the call, and
     * claim it so hook adapters cannot record it too. A failure is recorded as
     * a `capture_error` marker and that ref is left to the hook adapters.
     *
     * @param array<string, mixed> $args
     * @param array<string, true> $seen Out: keys of every ref declared up front.
     * @return list<array{0: ChangeSnapshotter, 1: SnapshotRef, 2: array<string, mixed>|null}>
     */
    private function snapshotBefore(string $ability, array $args, array &$seen): array
    {
        $snaps = [];
        foreach ($this->adapters->snapshotters() as $snapshotter) {
            try {
                if (!$snapshotter->handles($ability)) {
                    continue;
                }
                foreach ($snapshotter->refs($ability, $args) as $ref) {
                    if (isset($seen[$ref->key()])) {
                        continue;
                    }
                    $seen[$ref->key()] = true;
                    $state = $snapshotter->snapshot($ref);
                    $this->window->claim($ref->objectType, $ref->objectId);
                    $snaps[] = [$snapshotter, $ref, $state];
                }
            } catch (\Throwable) {
                $this->snapshotError();
            }
        }

        return $snaps;
    }

    /**
     * Snapshot again after the call and record a Change for each ref whose
     * state differs; an unchanged ref records nothing. Never throws.
     *
     * @param list<array{0: ChangeSnapshotter, 1: SnapshotRef, 2: array<string, mixed>|null}> $snaps
     */
    private function snapshotAfter(array $snaps): void
    {
        foreach ($snaps as [$snapshotter, $ref, $before]) {
            try {
                $after = $snapshotter->snapshot($ref);
                if ($before === null && $after === null) {
                    continue;
                }
                $action = match (true) {
                    $before === null => ChangeAction::Create,
                    $after === null => ChangeAction::Delete,
                    default => ChangeAction::Update,
                };
                $change = Change::capture($ref->objectType, $ref->objectId, $ref->objectSubtype, $action, $before, $after, $this->window->redactsPii());
                if ($action === ChangeAction::Update && $change->changedFields === []) {
                    continue;
                }
                $this->window->recordSnapshot($change);
            } catch (\Throwable) {
                $this->snapshotError();
            }
        }
    }

    /**
     * Objects only the result reveals (creates): recorded as before = absent,
     * after = a fresh snapshot, and claimed. A WP_Error or Throwable result
     * yields none; a refs-from-result or snapshot failure is a marker.
     *
     * @param array<string, mixed> $args
     * @param array<string, true>  $declared
     */
    private function snapshotCreated(string $ability, array $args, mixed $result, array $declared): void
    {
        if ($result instanceof \Throwable || $result instanceof \WP_Error) {
            return;
        }

        foreach ($this->adapters->snapshotters() as $snapshotter) {
            if (!$snapshotter instanceof ResultAwareSnapshotter) {
                continue;
            }
            try {
                if (!$snapshotter->handles($ability)) {
                    continue;
                }
                foreach ($snapshotter->refsFromResult($ability, $args, $result) as $ref) {
                    if (isset($declared[$ref->key()])) {
                        continue;
                    }
                    $declared[$ref->key()] = true;
                    $this->window->claim($ref->objectType, $ref->objectId);
                    $after = $snapshotter->snapshot($ref);
                    if ($after === null) {
                        continue;
                    }
                    $this->window->recordSnapshot(Change::capture($ref->objectType, $ref->objectId, $ref->objectSubtype, ChangeAction::Create, null, $after, $this->window->redactsPii()));
                }
            } catch (\Throwable) {
                $this->snapshotError();
            }
        }
    }

    private function snapshotError(): void
    {
        try {
            $this->window->recordSnapshot(Change::capture('window', 'capture_error', 'snapshot', ChangeAction::Irreversible, null, ['listener' => 'snapshot']));
        } catch (\Throwable) {
            // Capturing never changes a call's outcome.
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
        $key = $this->settledKey($ability);
        if ($key === null) {
            return null;
        }

        $entry = array_pop($this->settled[$key]);
        if (empty($this->settled[$key])) {
            unset($this->settled[$key]);
        }

        return $entry;
    }

    /**
     * Which settled ability a name refers to: the exact ability id first; else
     * the mcp-adapter tool-name form of a settled id, and only when exactly one
     * matches. mcp.request carries only `tool_name`, so the MCP audit writer
     * asks by that form. Ambiguous or unmatched means no match: a window is
     * never attached to a call it might not belong to.
     */
    private function settledKey(string $name): ?string
    {
        if (!empty($this->settled[$name])) {
            return $name;
        }

        $matches = [];
        foreach ($this->settled as $id => $entries) {
            if ($entries !== [] && self::toolName((string) $id) === $name) {
                $matches[] = (string) $id;
            }
        }

        return count($matches) === 1 ? $matches[0] : null;
    }

    /**
     * The tool name mcp-adapter derives from an ability id (McpNameSanitizer::
     * sanitize_name(), includes/Domain/Utils/McpNameSanitizer.php:73-91 at
     * upstream HEAD 07c9912): `/` becomes `-`; a name still containing
     * characters outside [a-zA-Z0-9_.-] has them replaced by `-`, runs of `-`
     * collapsed and edge `-`/`_` trimmed. Over-length names (hash-suffixed
     * upstream) are not reproduced: they simply never match, which is safe.
     */
    private static function toolName(string $ability): string
    {
        $name = str_replace('/', '-', trim($ability));
        if (preg_match('/^[a-zA-Z0-9_.-]+$/', $name) === 1) {
            return $name;
        }

        $name = (string) preg_replace('/[^a-zA-Z0-9_.-]/', '-', $name);
        $name = (string) preg_replace('/-+/', '-', $name);

        return trim($name, '-_');
    }

    private function settle(?ClosedWindow $closed): void
    {
        if ($closed === null) {
            return; // an inner call joining the outer window, or nothing open
        }

        foreach ($this->adapters->capture() as $adapter) {
            $adapter->detach();
        }

        try {
            $this->store->insertWindow($closed, gmdate('Y-m-d H:i:s'));
        } catch (\Throwable) {
            // Fail soft on storage only: see class docblock.
        }

        $this->settled[$this->outer][] = ['event_id' => $closed->eventId, 'changes_sha256' => $closed->sha256()];
    }
}
