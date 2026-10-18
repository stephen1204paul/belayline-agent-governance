<?php

declare(strict_types=1);

// Global-namespace multisite shims driven by Fakes/NetworkFake (inert until a test boots it).

if (!function_exists('get_current_blog_id')) {
    function get_current_blog_id(): int
    {
        return $GLOBALS['wpas_test_net']['current'] ?? 1;
    }
}

if (!function_exists('switch_to_blog')) {
    function switch_to_blog(int $blogId): bool
    {
        \Belayline\Plugin\Tests\Fakes\NetworkFake::switchTo($blogId);

        return true;
    }
}

if (!function_exists('restore_current_blog')) {
    function restore_current_blog(): bool
    {
        \Belayline\Plugin\Tests\Fakes\NetworkFake::restore();

        return true;
    }
}

if (!function_exists('get_sites')) {
    /**
     * @param array<string, mixed> $args
     * @return list<int>
     */
    function get_sites(array $args = []): array
    {
        $GLOBALS['wpas_test_net']['get_sites_calls'][] = $args;
        $number = (int) ($args['number'] ?? 100);
        $offset = (int) ($args['offset'] ?? 0);

        return array_slice($GLOBALS['wpas_test_net']['sites'] ?? [], $offset, $number);
    }
}

if (!function_exists('get_site_option')) {
    /** @param mixed $default @return mixed */
    function get_site_option(string $name, $default = false)
    {
        return $GLOBALS['wpas_test_net']['site_options'][$name] ?? $default;
    }
}

if (!function_exists('is_admin')) {
    function is_admin(): bool
    {
        return !empty($GLOBALS['wpas_test_net']['is_admin']);
    }
}

if (!function_exists('wp_doing_cron')) {
    function wp_doing_cron(): bool
    {
        return !empty($GLOBALS['wpas_test_net']['doing_cron']);
    }
}
