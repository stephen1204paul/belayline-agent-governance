<?php

declare(strict_types=1);

namespace Belayline\Plugin\Changes;

use Belayline\Audit\AuditDecision;
use Belayline\Audit\AuditRecord;
use Belayline\Audit\AuditSink;
use Belayline\Changes\ChangeLedger;
use Belayline\Changes\Drift;
use Belayline\Changes\StoredChange;
use Belayline\Changes\UndoItem;
use Belayline\Changes\UndoResult;
use Belayline\Changes\UndoScope;
use Belayline\Plugin\Support\AdminChangeRecorder;
use Belayline\Plugin\Support\RequestContext;

/**
 * The undo engine (the 0.5 spec section 3.2). Humans only: undo is not
 * an ability, the caller must be the logged-in user it names, must hold each
 * adapter's capability, and a request authenticated as an agent credential is
 * refused outright.
 *
 * Order: reverse recording order within scope. For every change: stored
 * refusals (irreversible, truncated, redacted, already restored) and the
 * adapter's `canRestore` SKIP it and go on; a missing object, drift (current
 * state on the changed fields differs from the recorded `after`; there is no
 * force) or a platform error STOPS the run, and everything after it is
 * reported as pending. A missing capability anywhere in scope refuses the
 * whole run before anything is written.
 *
 * The restores run inside a capture window under a fresh event id and their
 * own correlation id (`undo:<event>`, so an undo never joins the run it undid), so the undo
 * is recorded like any other change and can itself be undone. When at least one
 * change was restored, an audit row (decision `restored`, same event id,
 * `changes_sha256`) is appended and each restored change is stamped with that
 * event. A run that restored nothing wrote nothing and appends no row.
 */
final class UndoService
{
    public const ABILITY = 'undo';

    public function __construct(
        private readonly ChangeLedger $ledger,
        private readonly ChangeAdapters $adapters,
        private readonly ChangeCapture $capture,
        private readonly ?AuditSink $sink = null,
    ) {
    }

    public function undo(UndoScope $scope, int $userId): UndoResult
    {
        if ($this->capture->isWindowOpen()) {
            return new UndoResult(UndoResult::FORBIDDEN, [], null, UndoResult::REASON_INSIDE_WINDOW);
        }
        if ($userId <= 0 || get_current_user_id() !== $userId) {
            return new UndoResult(UndoResult::FORBIDDEN, [], null, UndoResult::REASON_USER_MISMATCH);
        }
        if (RequestContext::tokenId() !== null) {
            return new UndoResult(UndoResult::FORBIDDEN, [], null, UndoResult::REASON_AGENT_CONTEXT);
        }

        $changes = array_reverse($this->load($scope));
        if ($changes === []) {
            return new UndoResult(UndoResult::EMPTY);
        }

        foreach ($changes as $change) {
            $capability = $this->adapters->restoreFor($change->objectType)?->capability($change) ?? 'manage_options';
            if (!user_can($userId, $capability)) {
                return new UndoResult(UndoResult::FORBIDDEN, [], null, UndoResult::REASON_CAPABILITY);
            }
        }

        $eventId = RequestContext::event();
        $items = [];
        $failed = false;
        $correlation = 'undo:' . $eventId;
        $this->capture->runWindow(self::ABILITY, $eventId, $correlation, function () use ($changes, &$items, &$failed): void {
            foreach ($changes as $change) {
                if ($failed) {
                    $items[] = self::item($change, UndoItem::PENDING);
                    continue;
                }
                $item = $this->restoreOne($change);
                $items[] = $item;
                $failed = $item->status === UndoItem::FAILED;
            }
        });

        $settled = $this->capture->takeSettled(self::ABILITY);
        $eventId = $settled['event_id'] ?? $eventId;
        $restored = array_values(array_filter($items, static fn (UndoItem $i): bool => $i->status === UndoItem::RESTORED));

        if ($restored !== []) {
            $ids = array_map(static fn (UndoItem $i): int => $i->changeId, $restored);
            $this->ledger->markRestored($ids, $eventId);
            $this->audit($scope, $userId, $eventId, $correlation, $ids, $items, $settled['changes_sha256'] ?? null);
        }

        $status = match (true) {
            $restored === [] => UndoResult::REFUSED,
            $failed => UndoResult::PARTIAL,
            default => UndoResult::COMPLETE,
        };

        return new UndoResult($status, $items, $restored === [] ? null : $eventId);
    }

    /**
     * What an undo of this scope would do right now, without writing: per
     * change, skipped (with reason), or restorable, or drifted (three-way
     * diff). Evaluated against live state, so a change that only becomes
     * restorable after a later one in the same scope is undone may show drift.
     *
     * @return list<UndoItem> Reverse recording order.
     */
    public function preview(UndoScope $scope): array
    {
        $items = [];
        foreach (array_reverse($this->load($scope)) as $change) {
            $items[] = $this->evaluate($change) ?? self::item($change, UndoItem::RESTORABLE);
        }

        return $items;
    }

    /** @return list<StoredChange> */
    private function load(UndoScope $scope): array
    {
        return match ($scope->kind) {
            UndoScope::CHANGE => ($one = $this->ledger->find((int) $scope->id)) === null ? [] : [$one],
            UndoScope::EVENT => $this->ledger->forEvent($scope->id),
            default => $this->ledger->forCorrelation($scope->id),
        };
    }

    /** Null means every check passed and the change can be restored now. */
    private function evaluate(StoredChange $change): ?UndoItem
    {
        $reason = $change->refusal();
        if ($reason !== null) {
            return self::item($change, UndoItem::SKIPPED, $reason);
        }

        $adapter = $this->adapters->restoreFor($change->objectType);
        if ($adapter === null) {
            return self::item($change, UndoItem::SKIPPED, UndoItem::REASON_NO_ADAPTER);
        }
        $reason = $adapter->canRestore($change);
        if ($reason !== null) {
            return self::item($change, UndoItem::SKIPPED, $reason);
        }

        try {
            $current = $adapter->currentState($change);
        } catch (\Throwable $e) {
            return self::item($change, UndoItem::FAILED, UndoItem::REASON_RESTORE_FAILED, [], $e->getMessage());
        }
        if ($current === null) {
            return self::item($change, UndoItem::FAILED, UndoItem::REASON_OBJECT_MISSING);
        }

        $drift = Drift::compare($change, $current, $adapter->volatileFields());

        return $drift === [] ? null : self::item($change, UndoItem::FAILED, UndoItem::REASON_DRIFT, $drift);
    }

    private function restoreOne(StoredChange $change): UndoItem
    {
        $blocked = $this->evaluate($change);
        if ($blocked !== null) {
            return $blocked;
        }

        $adapter = $this->adapters->restoreFor($change->objectType);
        if ($adapter === null) {
            return self::item($change, UndoItem::SKIPPED, UndoItem::REASON_NO_ADAPTER);
        }

        try {
            $adapter->restore($change);
        } catch (\Throwable $e) {
            return self::item($change, UndoItem::FAILED, UndoItem::REASON_RESTORE_FAILED, [], $e->getMessage());
        }

        return self::item($change, UndoItem::RESTORED);
    }

    /**
     * @param list<array{field: string, before: mixed, after: mixed, current: mixed}> $drift
     */
    private static function item(StoredChange $change, string $status, ?string $reason = null, array $drift = [], ?string $message = null): UndoItem
    {
        return new UndoItem($change->id, $change->objectType, $change->objectId, $status, $reason, $drift, $message);
    }

    /**
     * @param list<int>      $restoredIds
     * @param list<UndoItem> $items
     */
    private function audit(UndoScope $scope, int $userId, string $eventId, string $correlation, array $restoredIds, array $items, ?string $changesSha256): void
    {
        if ($this->sink === null) {
            return;
        }

        $failure = null;
        foreach ($items as $item) {
            if ($item->status === UndoItem::FAILED) {
                $failure = ['change' => $item->changeId, 'reason' => $item->reason];
                break;
            }
        }

        $this->sink->append(AuditRecord::decision(
            id: $eventId,
            ts: RequestContext::nowUtc(),
            correlationId: $correlation,
            pack: AdminChangeRecorder::PACK,
            actor: RequestContext::actor(),
            ability: self::ABILITY,
            tier: null,
            input: ['scope' => $scope->kind, 'scope_id' => $scope->id, 'user' => $userId, 'restored' => $restoredIds, 'stopped_at' => $failure],
            decision: AuditDecision::Restored,
            approval: null,
            ip: RequestContext::ip(),
            reason: 'undo.' . $scope->kind,
            changesSha256: $changesSha256,
        ));
    }
}
