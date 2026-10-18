<?php

declare(strict_types=1);

namespace Belayline\Plugin\Admin\Map;

/**
 * Maps the audit log onto CALLS and their outcomes (the overview map and the
 * Activity filter). Display only: nothing here feeds a gate, approval or audit
 * decision.
 *
 * One audit row is not one call, so the rules pick exactly one row per call:
 *
 *   allowed + success (or no result)       Ran. An `allowed` row is the EXECUTION row;
 *                                          the gate writes no row for a plain allow.
 *   allowed + failure / error / other      Failed (an unknown result fails visible)
 *   denied                                 Refused
 *   pending + its approval is `pending`    Waiting
 *   pending + approval `approved`/`in_flight`  Approved: a person said yes, the agent has not run it yet
 *   pending + approval `rejected`          Refused
 *   pending + approval `consumed`          not counted: the run that used the approval has its own
 *                                          `allowed` row, which is the call (counted as Ran)
 *   pending + approval expired, stale, voided or already swept   Expired
 *   denied/pending written in shadow mode (`dry_run`)            Would refuse: the call ran anyway
 *                                          (its execution row counts as Ran), so this box is an
 *                                          observation, shown only when there are any
 *   approved, rejected, grant, admin, restored, stale rows        not calls (a person's decision
 *                                          or a settings change); the decision shows through the
 *                                          pending row's approval status
 *
 * The link from a request to its approval is `belayline_approvals.audit_event_id`
 * = the pending row's `event_id`. The run that spends an approval is NOT linked
 * to it in the audit log (execution rows carry no approval id), which is why a
 * consumed approval is counted through the run, not the request.
 */
final class CallOutcome
{
    public const RAN = 'ran';
    public const APPROVED = 'approved';
    public const WAITING = 'waiting';
    public const REFUSED = 'refused';
    public const FAILED = 'failed';
    public const EXPIRED = 'expired';
    public const WOULD = 'would';

    /** Lane order, left to right. */
    public const ALL = [self::RAN, self::APPROVED, self::WAITING, self::REFUSED, self::EXPIRED, self::FAILED, self::WOULD];

    /** The five boxes the map always shows; the rest appear only when something landed there. */
    public const ALWAYS = [self::RAN, self::APPROVED, self::WAITING, self::REFUSED, self::FAILED];

    /** Decisions whose rows can be a call at all (the SQL still decides which). */
    public const CALL_DECISIONS = ['allowed', 'denied', 'pending'];

    /**
     * The outcome of one audit row as a SQL expression: one of the outcome ids,
     * or '' for a row that is not a call. Aliases: `l` the audit log, `a` the
     * LEFT JOINed approvals table. Literals only, so it can sit in a prepared
     * template. `dry_run` is read from its LAST occurrence in the record, which
     * canonical field order puts after any agent-supplied input.
     */
    public const SQL = "CASE"
        . " WHEN l.decision = 'allowed' THEN (CASE WHEN l.result IS NULL OR l.result = '' OR l.result = 'success' THEN 'ran' ELSE 'failed' END)"
        . " WHEN l.decision IN ('denied', 'pending') AND SUBSTRING(SUBSTRING_INDEX(l.record_json, '\"dry_run\":', -1), 1, 5) = 'true,' THEN 'would'"
        . " WHEN l.decision = 'denied' THEN 'refused'"
        . " WHEN l.decision = 'pending' THEN (CASE a.status WHEN 'pending' THEN 'waiting' WHEN 'approved' THEN 'approved' WHEN 'in_flight' THEN 'approved'"
        . " WHEN 'rejected' THEN 'refused' WHEN 'consumed' THEN '' ELSE 'expired' END)"
        . " ELSE '' END";

    /** PHP twin of the executed-call part of {@see SQL}, for the single-row feed. */
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
            self::EXPIRED => __('Expired', 'belayline-agent-governance'),
            self::FAILED => __('Failed', 'belayline-agent-governance'),
            self::WOULD => __('Would refuse', 'belayline-agent-governance'),
            default => $outcome,
        };
    }

    /** Badge tone for the kit (`ok`, `info`, `warn`, `bad`, or '' for neutral). */
    public static function tone(string $outcome): string
    {
        return match ($outcome) {
            self::RAN => 'ok',
            self::APPROVED => 'info',
            self::WAITING, self::WOULD => 'warn',
            self::REFUSED => 'bad',
            default => '',
        };
    }
}
