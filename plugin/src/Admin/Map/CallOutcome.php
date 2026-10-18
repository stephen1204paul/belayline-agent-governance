<?php

declare(strict_types=1);

namespace Belayline\Plugin\Admin\Map;

/**
 * Maps the audit log's own vocabularies onto the five outcomes the overview map
 * and the Activity filter speak. Display only: nothing here feeds a gate,
 * approval or audit decision.
 *
 * Audit `decision` values: allowed, denied, pending, approved, rejected, grant,
 * admin, restored, stale. An `allowed` row is an EXECUTION row and carries a
 * `result` of `success`, `failure`, `error` or `error: <reason>` (the gate
 * itself does not write a row for a plain allow).
 *
 *   allowed + success (or no result)          Ran
 *   allowed + failure / error / anything else Failed (unknown results fail visible)
 *   pending                                   Waiting
 *   approved                                  Approved
 *   denied, rejected                          Refused
 *   grant, admin, restored, stale             not an agent call: left out of the map
 *
 * An approved call leaves a trail of rows (pending, approved, then the allowed
 * run once the agent retries), and each row counts under its own outcome.
 */
final class CallOutcome
{
    public const RAN = 'ran';
    public const APPROVED = 'approved';
    public const WAITING = 'waiting';
    public const REFUSED = 'refused';
    public const FAILED = 'failed';

    /** Lane order, left to right. */
    public const ALL = [self::RAN, self::APPROVED, self::WAITING, self::REFUSED, self::FAILED];

    /** Decisions that describe an agent call at all. */
    public const CALL_DECISIONS = ['allowed', 'denied', 'pending', 'approved', 'rejected'];

    /** First-contact decisions: one per call attempt, used for "calls today". */
    public const ATTEMPT_DECISIONS = ['allowed', 'denied', 'pending'];

    public static function classify(string $decision, ?string $result): ?string
    {
        return match ($decision) {
            'allowed' => ($result === null || $result === '' || $result === 'success') ? self::RAN : self::FAILED,
            'denied', 'rejected' => self::REFUSED,
            'pending' => self::WAITING,
            'approved' => self::APPROVED,
            default => null,
        };
    }

    public static function label(string $outcome): string
    {
        return match ($outcome) {
            self::RAN => __('Ran', 'belayline-agent-governance'),
            self::APPROVED => __('Approved', 'belayline-agent-governance'),
            self::WAITING => __('Waiting', 'belayline-agent-governance'),
            self::REFUSED => __('Refused', 'belayline-agent-governance'),
            self::FAILED => __('Failed', 'belayline-agent-governance'),
            default => $outcome,
        };
    }

    /** Badge tone for the kit (`ok`, `info`, `warn`, `bad`, or '' for neutral). */
    public static function tone(string $outcome): string
    {
        return match ($outcome) {
            self::RAN => 'ok',
            self::APPROVED => 'info',
            self::WAITING => 'warn',
            self::REFUSED => 'bad',
            default => '',
        };
    }

    /**
     * SQL condition (no placeholders, literals only) selecting the audit rows
     * of one outcome, or '' for an unknown outcome.
     */
    public static function sql(string $outcome): string
    {
        return match ($outcome) {
            self::RAN => "(decision = 'allowed' AND (result IS NULL OR result = '' OR result = 'success'))",
            self::FAILED => "(decision = 'allowed' AND result IS NOT NULL AND result <> '' AND result <> 'success')",
            self::WAITING => "decision = 'pending'",
            self::APPROVED => "decision = 'approved'",
            self::REFUSED => "decision IN ('denied', 'rejected')",
            default => '',
        };
    }
}
