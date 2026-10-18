<?php

declare(strict_types=1);

namespace Belayline\Plugin\Support;

use wpdb;

/**
 * Removes everything this plugin stores for ONE site: its custom tables, its
 * options, its cron hooks and its transient rows. Driven by
 * {@see UninstallManifest}, the single list, and shared by the opted-in
 * uninstall (`plugin/uninstall.php`, once per site on multisite) and by site
 * deletion ({@see NetworkLifecycle::onUninitializeSite()}). Operates on the
 * current blog, so multisite callers `switch_to_blog()` first.
 */
final class SiteCleanup
{
    public static function run(wpdb $db): void
    {
        foreach (UninstallManifest::TABLE_BASENAMES as $base) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.DirectDatabaseQuery.NoCaching -- dropping a table this plugin owns exclusively; %i is the WP 6.2+ identifier placeholder, the name is a hard-coded manifest constant, never request input.
            $sql = $db->prepare('DROP TABLE IF EXISTS %i', $db->prefix . $base);
            if (is_string($sql)) {
                $db->query($sql);
            }
        }

        foreach (UninstallManifest::OPTIONS as $option) {
            delete_option($option);
        }

        foreach (UninstallManifest::CRON_HOOKS as $hook) {
            wp_clear_scheduled_hook($hook);
        }

        // Transients are bucketed per identity/approver, so delete by prefix,
        // covering both the value row and its timeout row.
        foreach (UninstallManifest::TRANSIENT_PREFIXES as $prefix) {
            $valueLike = $db->esc_like('_transient_' . $prefix) . '%';
            $timeoutLike = $db->esc_like('_transient_timeout_' . $prefix) . '%';

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- sweep of this plugin's own transient rows by name prefix.
            $sql = $db->prepare(
                'DELETE FROM %i WHERE option_name LIKE %s OR option_name LIKE %s',
                $db->options,
                $valueLike,
                $timeoutLike,
            );
            if (is_string($sql)) {
                $db->query($sql);
            }
        }
    }
}
