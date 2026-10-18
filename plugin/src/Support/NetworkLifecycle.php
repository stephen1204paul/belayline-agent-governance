<?php

declare(strict_types=1);

namespace Belayline\Plugin\Support;

use wpdb;

/**
 * Multisite lifecycle. Every site is independent: own tables (the `$wpdb`
 * prefix carries the blog id), options, crons, approvals, audit chain and
 * change history. There is no network-wide audit view.
 *
 *  - Activation installs the CURRENT site immediately; with network
 *    activation every other site installs lazily on its first admin or cron
 *    `init` (the schema version check), and has its crons scheduled if
 *    missing on every `init`.
 *  - New sites ({@see onInitializeSite()}) install at creation when the
 *    plugin is network-active.
 *  - Deleted sites ({@see onUninitializeSite()}) lose this plugin's tables and
 *    options when it was active there.
 *  - Network deactivation unschedules the crons on every site, in batches.
 *    Beyond {@see SITE_CAP} sites the remaining crons are left behind; they are
 *    inert (no callback is registered once the plugin is not loaded) and WordPress
 *    drops nothing on its own, so they simply idle until the next activation.
 *    A self-unscheduling guard is impossible: it would have to live in code
 *    that is not loaded when the plugin is inactive.
 */
final class NetworkLifecycle
{
    public const BATCH = 100;
    public const SITE_CAP = 10000;

    /** Full install of the current site: tables, migrations and every cron. */
    public static function installSite(wpdb $db): void
    {
        // Schema::install() also schedules the sweep, purge and retention crons.
        Schema::install($db);
    }

    /** Schedule any cron that is missing on the current site. */
    public static function ensureCrons(): void
    {
        ApprovalSweep::activate();
        ChangePurge::activate();
        AuditRetention::activate();
    }

    public static function unscheduleCrons(): void
    {
        ApprovalSweep::deactivate();
        ChangePurge::deactivate();
        AuditRetention::deactivate();
    }

    /**
     * Run $fn once per site (switching to it), in batches, up to $cap sites.
     * On a single-site install runs $fn once, with no switching. Returns the
     * number of sites visited.
     *
     * @param callable(int): void $fn Receives the blog id.
     */
    public static function forEachSite(callable $fn, int $batch = self::BATCH, int $cap = self::SITE_CAP): int
    {
        if (!function_exists('is_multisite') || !is_multisite()) {
            $fn(function_exists('get_current_blog_id') ? get_current_blog_id() : 1);

            return 1;
        }

        $visited = 0;
        $offset = 0;
        while ($visited < $cap) {
            $ids = get_sites([
                'fields' => 'ids',
                'number' => min($batch, $cap - $visited),
                'offset' => $offset,
                'orderby' => 'id',
                'order' => 'ASC',
            ]);
            if (!is_array($ids) || $ids === []) {
                break;
            }

            foreach ($ids as $id) {
                switch_to_blog((int) $id);
                try {
                    $fn((int) $id);
                } finally {
                    restore_current_blog();
                }
                ++$visited;
            }

            $offset += count($ids);
            if (count($ids) < $batch) {
                break;
            }
        }

        return $visited;
    }

    /** Network deactivation: unschedule the crons on every site. */
    public static function deactivateNetwork(): int
    {
        return self::forEachSite(static function (): void {
            self::unscheduleCrons();
        });
    }

    /** Whether the plugin is network-active (a network-wide `active_sitewide_plugins` entry). */
    public static function isNetworkActive(string $pluginBasename): bool
    {
        $active = function_exists('get_site_option') ? get_site_option('active_sitewide_plugins', []) : [];

        return is_array($active) && isset($active[$pluginBasename]);
    }

    /** Whether the plugin is active on the CURRENT site, network-wide or locally. */
    public static function isActiveHere(string $pluginBasename): bool
    {
        if (self::isNetworkActive($pluginBasename)) {
            return true;
        }

        return in_array($pluginBasename, (array) get_option('active_plugins', []), true);
    }

    /**
     * `wp_initialize_site` (priority after core's own table creation): install
     * a brand-new site when the plugin is network-active.
     *
     */
    public static function onInitializeSite(int $blogId, wpdb $db, string $pluginBasename): void
    {
        if (!self::isNetworkActive($pluginBasename)) {
            return;
        }

        switch_to_blog($blogId);
        try {
            self::installSite($db);
        } finally {
            restore_current_blog();
        }
    }

    /**
     * `wp_uninitialize_site` (before core drops its own tables): remove this
     * plugin's tables and options from a site being deleted, when it was
     * active there.
     *
     */
    public static function onUninitializeSite(int $blogId, wpdb $db, string $pluginBasename): void
    {
        switch_to_blog($blogId);
        try {
            if (self::isActiveHere($pluginBasename)) {
                SiteCleanup::run($db);
            }
        } finally {
            restore_current_blog();
        }
    }

    /** The blog id off a WP_Site (or any object with a public `blog_id`). */
    private static function blogIdOf(mixed $site): int
    {
        return is_object($site) ? (int) (((array) $site)['blog_id'] ?? 0) : 0;
    }

    /**
     * Register the multisite runtime hooks. No-op on a single site.
     */
    public static function register(wpdb $db, string $pluginFile): void
    {
        if (!function_exists('is_multisite') || !is_multisite()) {
            return;
        }

        $basename = plugin_basename($pluginFile);

        // Roles and app passwords are per-site: forget the memoized principal
        // whenever the blog changes.
        add_action('switch_blog', [RequestContext::class, 'forgetPrincipal']);

        // Lazy install for sites that never saw the activation hook
        // (network activation, or a site created before the plugin existed):
        // admin and cron requests run the schema version check, then every
        // request makes sure the crons exist.
        add_action('init', static function () use ($db): void {
            $admin = function_exists('is_admin') && is_admin();
            $cron = function_exists('wp_doing_cron') && wp_doing_cron();
            if ($admin || $cron) {
                Schema::maybeUpgrade($db);
            }
            self::ensureCrons();
        });

        add_action('wp_initialize_site', static function ($site) use ($db, $basename): void {
            self::onInitializeSite(self::blogIdOf($site), $db, $basename);
        }, 20);

        add_action('wp_uninitialize_site', static function ($site) use ($db, $basename): void {
            self::onUninitializeSite(self::blogIdOf($site), $db, $basename);
        }, 5);
    }
}
