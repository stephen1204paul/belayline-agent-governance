<?php

declare(strict_types=1);

namespace Belayline\Plugin\Tests\Fakes;

/**
 * Minimal multisite world for unit tests. Options and the cron table are
 * per-blog (swapped in and out of the `wpas_test_options` / `wpas_test_cron`
 * globals the single-site shims already use), `switch_to_blog()` also moves
 * `$wpdb->prefix` and dispatches the recorded `switch_blog` callbacks, and
 * `get_sites()` pages over a configured id list. Nothing here is active until
 * a test calls {@see boot()}, so single-site tests are unaffected.
 */
final class NetworkFake
{
/**
 * @param list<int> $siteIds
 */
    public static function boot(array $siteIds, int $current = 1, \wpdb $db = new \wpdb()): \wpdb
    {
        $GLOBALS['wpas_test_multisite'] = true;
        $GLOBALS['wpas_test_net'] = [
        'sites' => $siteIds,
        'current' => $current,
        'stack' => [],
        'blogs' => [],
        'site_options' => [],
        'get_sites_calls' => [],
        'visited' => [],
        'is_admin' => false,
        'doing_cron' => false,
        ];
        $GLOBALS['wpas_test_options'] = [];
        $GLOBALS['wpas_test_cron'] = [];
        $GLOBALS['wpdb'] = $db;
        $db->prefix = self::prefixFor($current);

        return $db;
    }

    public static function reset(): void
    {
        unset($GLOBALS['wpas_test_net'], $GLOBALS['wpas_test_multisite'], $GLOBALS['wpdb']);
        $GLOBALS['wpas_test_options'] = [];
        $GLOBALS['wpas_test_cron'] = [];
        $GLOBALS['wpas_test_added_actions'] = [];
        $GLOBALS['wpas_test_filters'] = [];
    }

    public static function prefixFor(int $blogId): string
    {
        return $blogId === 1 ? 'wp_' : 'wp_' . $blogId . '_';
    }

/** Seed a site's own options/cron without switching to it. @param array<string, mixed> $options @param array<string, int> $cron */
    public static function seed(int $blogId, array $options = [], array $cron = []): void
    {
        if ($blogId === $GLOBALS['wpas_test_net']['current']) {
            $GLOBALS['wpas_test_options'] = $options + $GLOBALS['wpas_test_options'];
            $GLOBALS['wpas_test_cron'] = $cron + $GLOBALS['wpas_test_cron'];

            return;
        }
        $GLOBALS['wpas_test_net']['blogs'][$blogId] = ['options' => $options, 'cron' => $cron];
    }

/** @return array{options: array<string, mixed>, cron: array<string, int>} */
    public static function state(int $blogId): array
    {
        if ($blogId === $GLOBALS['wpas_test_net']['current']) {
            return ['options' => $GLOBALS['wpas_test_options'], 'cron' => $GLOBALS['wpas_test_cron']];
        }

        return $GLOBALS['wpas_test_net']['blogs'][$blogId] ?? ['options' => [], 'cron' => []];
    }

    public static function switchTo(int $blogId): void
    {
        $net = &$GLOBALS['wpas_test_net'];
        $previous = $net['current'];
        $net['stack'][] = $previous;
        $net['blogs'][$previous] = ['options' => $GLOBALS['wpas_test_options'], 'cron' => $GLOBALS['wpas_test_cron']];
        $incoming = $net['blogs'][$blogId] ?? ['options' => [], 'cron' => []];
        $GLOBALS['wpas_test_options'] = $incoming['options'];
        $GLOBALS['wpas_test_cron'] = $incoming['cron'];
        $net['current'] = $blogId;
        $net['visited'][] = $blogId;
        if (isset($GLOBALS['wpdb'])) {
            $GLOBALS['wpdb']->prefix = self::prefixFor($blogId);
        }
        foreach ($GLOBALS['wpas_test_added_actions']['switch_blog'] ?? [] as $callback) {
            $callback($blogId, $previous);
        }
    }

    public static function restore(): void
    {
        $net = &$GLOBALS['wpas_test_net'];
        $previous = array_pop($net['stack']);
        if ($previous === null) {
            return;
        }
        self::switchTo($previous);
        array_pop($net['stack']); // switchTo() pushed; a restore is not a nested switch.
        array_pop($net['visited']);
    }

/** Fire every callback recorded for a hook (the add_action shim only records). @param mixed ...$args */
    public static function fire(string $hook, ...$args): void
    {
        foreach ($GLOBALS['wpas_test_added_actions'][$hook] ?? [] as $callback) {
            $callback(...$args);
        }
    }
}
