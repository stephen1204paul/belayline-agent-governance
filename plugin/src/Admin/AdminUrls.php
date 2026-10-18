<?php

declare(strict_types=1);

namespace Belayline\Plugin\Admin;

/**
 * The one place that knows where each Belayline tab lives. Every link, redirect
 * and email in the plugin goes through here, so the menu can move again without
 * a grep. Tabs are separate admin pages (`admin.php?page=belayline-...`) under
 * one top-level menu; Activity has two sub-views selected with `section`.
 */
final class AdminUrls
{
    public const OVERVIEW = 'overview';
    public const PENDING = 'pending';
    public const ACTIVITY = 'activity';
    public const PACKS = 'packs';
    public const SETTINGS = 'settings';

    /** tab => admin page slug. The Overview owns the top-level slug. */
    public const SLUGS = [
        self::OVERVIEW => 'belayline',
        self::PENDING => 'belayline-pending',
        self::ACTIVITY => 'belayline-activity',
        self::PACKS => 'belayline-packs',
        self::SETTINGS => 'belayline-settings',
    ];

    /** Activity sub-views. */
    public const CALLS = 'calls';
    public const CHANGES = 'changes';

    /**
     * Old `tools.php?page=` slug => [tab, extra query args, fragment]. Used by
     * {@see AdminApp} to keep every bookmarked or emailed URL working.
     */
    public const LEGACY = [
        'belayline-pending' => [self::PENDING, [], ''],
        'belayline-packs' => [self::PACKS, [], ''],
        'belayline-abilities' => [self::PACKS, [], 'abilities'],
        'belayline-audit' => [self::ACTIVITY, ['section' => self::CALLS], ''],
        'belayline-changes' => [self::ACTIVITY, ['section' => self::CHANGES], ''],
    ];

    /**
     * @param array<string, scalar|list<scalar>> $args
     */
    public static function tab(string $tab, array $args = [], string $fragment = ''): string
    {
        $slug = self::SLUGS[$tab] ?? self::SLUGS[self::OVERVIEW];
        $url = add_query_arg(['page' => $slug] + $args, admin_url('admin.php'));

        return $fragment === '' ? $url : $url . '#' . $fragment;
    }

    /**
     * Activity > Calls or Changes.
     *
     * @param array<string, scalar|list<scalar>> $args
     */
    public static function activity(string $section = self::CALLS, array $args = []): string
    {
        return self::tab(self::ACTIVITY, ['section' => $section] + $args);
    }

    /** A tab name from request input, or the Overview when it is not one of ours. */
    public static function safeTab(mixed $raw): string
    {
        $tab = is_scalar($raw) ? sanitize_key((string) $raw) : '';

        return isset(self::SLUGS[$tab]) ? $tab : self::OVERVIEW;
    }
}
