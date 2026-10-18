<?php

/**
 * Runs on "Delete" from the Plugins screen (WordPress requires this to be a
 * standalone file — no bootstrap, no autoloader guaranteed).
 *
 * Default behaviour: KEEP all data. The audit log is a compliance record
 * (PCI Req-10 shape); silently destroying it on uninstall would
 * defeat the point of having it. Table data and options are only dropped when
 * the site operator explicitly opts in by defining BELAYLINE_REMOVE_DATA as true
 * (e.g. in wp-config.php) before deleting the plugin.
 *
 * The explicit list of everything opting in removes lives in
 * {@see \Belayline\Plugin\Support\UninstallManifest}, one class
 * with no WordPress dependency, so it is safe to load below even if this
 * plugin's own bootstrap (belayline-agent-governance.php) never ran on this request —
 * exactly the standalone constraint this file operates under.
 */

declare(strict_types=1);

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

if (!defined('BELAYLINE_REMOVE_DATA') || BELAYLINE_REMOVE_DATA !== true) {
    return;
}

global $wpdb;
if (!isset($wpdb)) {
    return;
}

// Same bundled autoloader belayline-agent-governance.php uses: `composer install` in this
// dir copies the core package into vendor/ and wires PSR-4 for both the core
// and the plugin's own classes, including UninstallManifest. A site that
// deletes the plugin without ever having run `composer install` here (e.g. a
// hand-copied checkout) has no autoloader to load -- in that case there is
// nothing safe to remove beyond the tables this file names directly below,
// so option/cron/transient cleanup is skipped rather than guessed at.
$belaylineUninstallAutoload = __DIR__ . '/vendor/autoload.php';
$belaylineManifestLoaded = false;
if (is_readable($belaylineUninstallAutoload)) {
    require_once $belaylineUninstallAutoload;
    $belaylineManifestLoaded = class_exists(\Belayline\Plugin\Support\UninstallManifest::class);
}

// Everything below runs once per site: on multisite, uninstall walks every
// site (batches of 100, capped at 10,000) and runs the same cleanup on each.
// The opt-in is the BELAYLINE_REMOVE_DATA constant, which is network-wide by
// nature (wp-config.php), so there is no per-site opt-in to honour. The plugin
// writes no site (network) options, so nothing is removed network-wide.
if ($belaylineManifestLoaded && class_exists(\Belayline\Plugin\Support\NetworkLifecycle::class)) {
    \Belayline\Plugin\Support\NetworkLifecycle::forEachSite(static function () use ($wpdb): void {
        \Belayline\Plugin\Support\SiteCleanup::run($wpdb);
    });

    return;
}

// No autoloader (a hand-copied checkout): nothing safe to remove beyond the
// tables named directly here, on the current site only. %i is the WP 6.2+
// identifier placeholder; each name is a hard-coded constant.
foreach (['belayline_audit_log', 'belayline_approvals', 'belayline_grants', 'belayline_counters', 'belayline_changes', 'belayline_audit_checkpoint'] as $belaylineTableBasename) {
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.DirectDatabaseQuery.NoCaching -- opt-in uninstall dropping a table this plugin owns exclusively; %i is the WP 6.2+ identifier placeholder, the name is a hard-coded constant, never request input.
    $wpdb->query($wpdb->prepare('DROP TABLE IF EXISTS %i', $wpdb->prefix . $belaylineTableBasename));
}
