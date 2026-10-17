<?php

declare(strict_types=1);

namespace Belayline\Plugin\Support;

use Belayline\Plugin\Audit\AuditPruner;

/**
 * Daily retention for the hash-chained audit log. Unlike the change history
 * ({@see ChangePurge}) the default is to keep everything: the option is 0
 * until an operator chooses a window, so an upgrade never deletes audit rows
 * silently. Pruning writes a checkpoint so the chain still verifies; see
 * {@see AuditPruner}.
 *
 * Scheduling mirrors {@see ChangePurge}: {@see activate()} is idempotent and is
 * called from activation and {@see Schema::install()}, so an upgrade gets the
 * cron without a re-activation.
 */
final class AuditRetention
{
    public const HOOK = 'belayline_prune_audit_log';

    /** Days of audit history kept; 0 = keep forever. Not autoloaded. */
    public const OPTION = 'belayline_audit_retention_days';

    /** Upper bound accepted from the settings field (100 years), so a typo cannot overflow date maths. */
    public const MAX_DAYS = 36500;

    public static function activate(): void
    {
        if (wp_next_scheduled(self::HOOK) === false) {
            wp_schedule_event(time(), 'daily', self::HOOK);
        }
    }

    public static function deactivate(): void
    {
        wp_clear_scheduled_hook(self::HOOK);
    }

    /** Normalise any submitted/stored value to 0 (forever) or 1..MAX_DAYS. */
    public static function sanitizeDays(mixed $raw): int
    {
        $days = is_scalar($raw) ? absint($raw) : 0;

        return min($days, self::MAX_DAYS);
    }

    /** Configured retention in days; 0 means keep forever. */
    public static function days(): int
    {
        return self::sanitizeDays(get_option(self::OPTION, 0));
    }

    /** The cron callback. Returns the number of rows deleted (0 when retention is off). */
    public static function run(AuditPruner $pruner): int
    {
        $days = self::days();

        return $days === 0 ? 0 : $pruner->prune($days);
    }
}
