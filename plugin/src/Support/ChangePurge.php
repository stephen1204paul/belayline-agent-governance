<?php

declare(strict_types=1);

namespace Belayline\Plugin\Support;

use Belayline\Plugin\Changes\WpdbChangeStore;

/**
 * Daily retention purge of the change history table. Snapshot bodies can
 * hold customer data (an order's billing fields, a user-facing post), so they
 * are not kept forever: rows older than the retention window are deleted. The
 * audit row each belongs to is untouched — its `changes_sha256` still
 * records that a change set existed.
 *
 * Scheduling mirrors {@see ApprovalSweep}: {@see activate()} is idempotent and
 * is called from both activation and {@see Schema::install()}, so an upgrade
 * from a pre-v6 install gets the cron without a re-activation.
 */
final class ChangePurge
{
    public const HOOK = 'belayline_purge_changes';

    /** Days of change history kept. Not autoloaded; absent means {@see DEFAULT_DAYS}. */
    public const RETENTION_OPTION = 'belayline_changes_retention_days';

    public const DEFAULT_DAYS = 30;

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

    /** Configured retention in days, floored at 1: a garbage value never means "delete everything now". */
    public static function retentionDays(): int
    {
        $days = (int) get_option(self::RETENTION_OPTION, self::DEFAULT_DAYS);

        return $days >= 1 ? $days : self::DEFAULT_DAYS;
    }

    /** The cron callback. Returns the number of rows deleted. */
    public static function run(WpdbChangeStore $store): int
    {
        return $store->purgeOlderThan(gmdate('Y-m-d H:i:s', time() - self::retentionDays() * 86400));
    }
}
