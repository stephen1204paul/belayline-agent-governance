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
 *   allowed + approval_id, success         Approved: the run an approval paid for. The execution
 *                                          row names its approval (server-set, never from agent input)
 *   allowed + approval_id, not success     Failed (the approval went back to `approved` on rollback)
 *   allowed + approval_id, NOT the latest  not counted: a retry of the same approval is the same call,
 *                                          so only the newest execution per approval id counts
 *   denied                                 Refused
 *   pending + its approval is `pending`    Waiting
 *   pending + approval `approved`/`in_flight`  Approved: a person said yes, the agent has not run it yet
 *   pending + approval `rejected`          Refused
 *   pending + approval `consumed`          not counted: the run that used the approval is the call
 *   pending + ANY execution row names its approval   not counted: that execution is the call (this also
 *                                          covers a failed run, which returns the approval to `approved`)
 *   pending + approval expired, stale, voided or already swept   Expired
 *   denied/pending written in shadow mode (`dry_run`)            Would refuse: the call ran anyway
 *                                          (its execution row counts as Ran), so this box is an
 *                                          observation, shown only when there are any
 *   approved, rejected, grant, admin, restored, stale rows        not calls (a person's decision
 *                                          or a settings change); the decision shows through the
 *                                          pending row's approval status
 *
 * The link from a request to its approval is `belayline_approvals.audit_event_id`
 * = the pending row's `event_id`. The link from a run to its approval is the audit
 * row's `approval_id` column, a query aid mirroring the hashed record's top-level
 * `approval_id` (chain verification cross-checks the two). Rows written before
 * schema 9 have neither, and are classified exactly as they always were.
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
     * template. It holds exactly {@see TABLE_PLACEHOLDERS} `%i` placeholders, in
     * order, each taking the audit table's name: the "latest execution per
     * approval" and "was it executed" tests look back into the audit log.
     * `dry_run` is read from its LAST occurrence in the record, which canonical
     * field order puts after any agent-supplied input; the approval link is read
     * from the `approval_id` COLUMN, never parsed out of agent-reachable JSON.
     */
    public const SQL = "CASE"
        . " WHEN l.decision = 'allowed' THEN (CASE"
        . " WHEN l.approval_id IS NOT NULL AND l.id <> (SELECT MAX(x.id) FROM %i x WHERE x.approval_id = l.approval_id AND x.decision = 'allowed') THEN ''"
        . " WHEN l.approval_id IS NOT NULL THEN (CASE WHEN l.result IS NULL OR l.result = '' OR l.result = 'success' THEN 'approved' ELSE 'failed' END)"
        . " WHEN l.result IS NULL OR l.result = '' OR l.result = 'success' THEN 'ran' ELSE 'failed' END)"
        . " WHEN l.decision IN ('denied', 'pending') AND SUBSTRING(SUBSTRING_INDEX(l.record_json, '\"dry_run\":', -1), 1, 5) = 'true,' THEN 'would'"
        . " WHEN l.decision = 'denied' THEN 'refused'"
        . " WHEN l.decision = 'pending' AND a.approval_id IS NOT NULL AND EXISTS (SELECT 1 FROM %i y WHERE y.approval_id = a.approval_id AND y.decision = 'allowed') THEN ''"
        . " WHEN l.decision = 'pending' THEN (CASE a.status WHEN 'pending' THEN 'waiting' WHEN 'approved' THEN 'approved' WHEN 'in_flight' THEN 'approved'"
        . " WHEN 'rejected' THEN 'refused' WHEN 'consumed' THEN '' ELSE 'expired' END)"
        . " ELSE '' END";

    /** How many `%i` (audit table name) placeholders {@see SQL} takes. */
    public const TABLE_PLACEHOLDERS = 2;

    /**
     * The values for {@see SQL}'s table placeholders.
     *
     * @return list<string>
     */
    public static function tableArgs(string $auditTable): array
    {
        return array_fill(0, self::TABLE_PLACEHOLDERS, $auditTable);
    }

    /** PHP twin of the executed-call part of {@see SQL}, for the single-row feed. */
    public static function classify(string $decision, ?string $result, ?string $approvalId = null): ?string
    {
        return match ($decision) {
            'allowed' => ($result === null || $result === '' || $result === 'success')
                ? ($approvalId !== null && $approvalId !== '' ? self::APPROVED : self::RAN)
                : self::FAILED,
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
