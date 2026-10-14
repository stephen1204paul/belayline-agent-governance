<?php

declare(strict_types=1);

namespace Belayline\Plugin\Api;

use Belayline\Changes\ChangeLedger;
use Belayline\Changes\StoredChange;
use Belayline\Changes\UndoItem;
use Belayline\Changes\UndoResult;
use Belayline\Changes\UndoScope;
use Belayline\Plugin\Changes\UndoService;

/**
 * The change-history API (the 0.5 spec section 3.5), reached through
 * `belayline()->changes()`.
 *
 * Reading is the caller's to gate (the data can include customer fields):
 * check a capability before showing it. Undoing is enforced HERE, not by the
 * caller: {@see undo()} refuses unless `$user_id` is the current logged-in user,
 * holds every capability the scope needs, and the request is not an agent
 * credential or inside a governed call. A nonce check is the caller's job (a
 * human click on its own screen); this API never accepts an agent as the actor.
 *
 *   $changes = belayline()?->changes();
 *   $run     = $changes?->forCorrelation($correlation_id);
 *   $preview = $changes?->preview($run->scope());
 *   $result  = $changes?->undo($run->scope(), get_current_user_id());
 */
final class Changes
{
    public function __construct(
        private readonly ChangeLedger $ledger,
        private readonly UndoService $undo,
    ) {
    }

    /** Every change recorded under one correlation id (a run), oldest first. */
    public function forCorrelation(string $correlationId): RecordedChanges
    {
        return self::wrap(UndoScope::correlation($correlationId), $this->ledger->forCorrelation($correlationId));
    }

    /** Every change recorded for one audit event, in recording order. */
    public function forEvent(string $eventId): RecordedChanges
    {
        return self::wrap(UndoScope::event($eventId), $this->ledger->forEvent($eventId));
    }

    /**
     * What undoing this scope would do right now, without writing anything:
     * per change `restorable`, `skipped` (with reason) or `failed` (drift, with
     * the three-way difference). Reverse recording order.
     *
     * @return list<UndoItem>
     */
    public function preview(UndoScope $scope): array
    {
        return $this->undo->preview($scope);
    }

    /**
     * Undo a change, an event or a whole run, with the same drift check and
     * audit row as the Changes screen. Never forces. Check
     * {@see UndoResult::$status}: `complete`, `partial`, `refused`, `forbidden`
     * (reason `capability`, `user_mismatch`, `agent_context`,
     * `inside_governed_call`) or `empty`.
     */
    public function undo(UndoScope $scope, int $user_id): UndoResult
    {
        return $this->undo->undo($scope, $user_id);
    }

    /** @param list<StoredChange> $stored */
    private static function wrap(UndoScope $scope, array $stored): RecordedChanges
    {
        return new RecordedChanges($scope, array_map(static fn (StoredChange $c): RecordedChange => new RecordedChange($c), $stored));
    }
}
