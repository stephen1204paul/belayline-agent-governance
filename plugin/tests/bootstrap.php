<?php

/**
 * PHPUnit bootstrap for the plugin test suite. Run from woo-belayline/ via:
 *   vendor/bin/phpunit -c plugin/phpunit.xml.dist
 *
 * The plugin has no WordPress (or mcp-adapter) install available in CI/local
 * runs, so this defines the minimal WP function shims the code under test
 * actually calls, plus a stand-in for mcp-adapter's public observability
 * interface — ONLY when the real thing isn't already loaded, so this stays a
 * no-op the moment a real WP/mcp-adapter environment (e.g. wp-env) supplies
 * the genuine functions/classes instead.
 */

declare(strict_types=1);

$wpasPluginAutoload = __DIR__ . '/../vendor/autoload.php';
if (!is_readable($wpasPluginAutoload)) {
    fwrite(STDERR, "Missing plugin/vendor/autoload.php — run `composer install` in plugin/ first.\n");
    exit(1);
}
require_once $wpasPluginAutoload;

// Real WordPress defines this before loading any plugin file; api.php (like
// belayline-agent-governance.php) exits immediately without it.
if (!defined('ABSPATH')) {
    define('ABSPATH', __DIR__ . '/');
}

// The global belayline() service locator — function_exists-guarded, and
// require-once idempotent with the real plugin main file's own load of it.
require_once __DIR__ . '/../src/api.php';

// Test-only stand-in for mcp-adapter's public observability contract.
if (!interface_exists(\WP\MCP\Infrastructure\Observability\Contracts\McpObservabilityHandlerInterface::class, false)) {
    require_once __DIR__ . '/stubs/mcp-observability-handler-interface.php';
}

// Test-only stand-in for WordPress's $wpdb (a real WP load order always wins).
if (!class_exists('wpdb', false)) {
    require_once __DIR__ . '/stubs/wpdb.php';
}

// Test-only stand-in for WordPress's WP_Error (a real WP load order always
// wins). Needed the moment a test drives a gate seam far enough to hit a
// denial/approval-required/execution-failure branch, all of which construct one.
if (!class_exists('WP_Error', false)) {
    require_once __DIR__ . '/stubs/wp-error.php';
}

// Test-only clock override consumed by Support\RateCounter's fixed-window
// bucketing (see the file for why this is a namespaced function, not a class).
require_once __DIR__ . '/stubs/wpas-clock.php';

// Hand-rolled fakes used across the suite.
require_once __DIR__ . '/Fakes/InMemoryAuditSink.php';
require_once __DIR__ . '/Fakes/FakeApprovalStore.php';
require_once __DIR__ . '/Fakes/FakeGrantStore.php';
require_once __DIR__ . '/Fakes/FakeIdentityProvider.php';
require_once __DIR__ . '/Fakes/FakeToolAnnotations.php';
require_once __DIR__ . '/Fakes/FakeMcpTool.php';
require_once __DIR__ . '/Fakes/AdapterBundle.php';
require_once __DIR__ . '/Fakes/InMemoryChangeLedger.php';
require_once __DIR__ . '/Fakes/FakeStoreSnapshotter.php';
require_once __DIR__ . '/Fakes/FakeCreatingSnapshotter.php';
require_once __DIR__ . '/Fakes/WidgetAdapter.php';
require_once __DIR__ . '/Fakes/ChainingSink.php';
require_once __DIR__ . '/Fakes/InMemoryAuditRowsWpdb.php';
require_once __DIR__ . '/Fakes/CounterTableWpdb.php';
require_once __DIR__ . '/Fakes/SqliteWpdb.php';
require_once __DIR__ . '/Fakes/RedirectException.php';
require_once __DIR__ . '/Fakes/NetworkFake.php';
require_once __DIR__ . '/Fakes/FailingInstallWpdb.php';
require_once __DIR__ . '/stubs/network-functions.php';
require_once __DIR__ . '/Fakes/FakeFluentCartDb.php';
require_once __DIR__ . '/Fixtures/VerdictErrorFixture.php';

// --- Minimal WP function shims -------------------------------------------
// Only defined when absent, so a real WP load order (wp-env) always wins.

if (!function_exists('get_current_user_id')) {
    $GLOBALS['wpas_test_current_user_id'] = 0;

    /** Test control knob: set $GLOBALS['wpas_test_current_user_id'] per-test. */
    function get_current_user_id(): int
    {
        return (int) ($GLOBALS['wpas_test_current_user_id'] ?? 0);
    }
}

if (!function_exists('wp_get_current_user')) {
    /**
     * The logged-in user as UserRoleIdentity reads it: ID from
     * wpas_test_current_user_id, roles from wpas_test_current_user_roles.
     */
    function wp_get_current_user(): object
    {
        return (object) [
            'ID' => get_current_user_id(),
            'roles' => $GLOBALS['wpas_test_current_user_roles'] ?? [],
        ];
    }
}

if (!function_exists('current_user_can')) {
    $GLOBALS['wpas_test_user_caps'] = [];

    /**
     * Test control knob: set $GLOBALS['wpas_test_user_caps'][$capability]
     * per-test; absent capabilities default to false (fail closed, like core).
     */
    function current_user_can(string $capability): bool
    {
        return (bool) ($GLOBALS['wpas_test_user_caps'][$capability] ?? false);
    }
}

if (!function_exists('user_can')) {
    $GLOBALS['wpas_test_user_caps_by_id'] = [];

    /**
     * Test control knob: set $GLOBALS['wpas_test_user_caps_by_id'][$userId][$capability]
     * per-test; absent users and capabilities default to false (fail closed).
     * Deliberately NOT backed by the current_user_can() knob above: the code
     * under test asks about a NAMED user (a grant's grantor), and a test that
     * flips the current user's capabilities must not pass that check by accident.
     */
    function user_can(int|object $user, string $capability): bool
    {
        $id = is_object($user) && isset($user->ID) ? (int) $user->ID : (int) $user;

        return (bool) ($GLOBALS['wpas_test_user_caps_by_id'][$id][$capability] ?? false);
    }
}

if (!function_exists('__')) {
    /**
     * Honors $GLOBALS['wpas_test_translations'][$domain][$text] so tests can
     * simulate a loaded textdomain (a non-English site) — both the emitting
     * code and any consumer mirroring the same __() call must then agree on
     * the SAME translated string, which is exactly the property under test.
     */
    function __(string $text, string $domain = 'default'): string
    {
        return $GLOBALS['wpas_test_translations'][$domain][$text] ?? $text;
    }
}

$GLOBALS['wpas_test_filters'] = [];

if (!function_exists('add_filter')) {
    /** @param mixed ...$args */
    function add_filter(string $hook, $callback, int $priority = 10, int $accepted_args = 1): bool
    {
        $GLOBALS['wpas_test_filters'][$hook][$priority][] = [$callback, $accepted_args];

        return true;
    }
}

if (!function_exists('apply_filters')) {
    /**
     * WP-style value threading through priority-ordered callbacks. A working
     * registry (was a pass-through no-op): with no registered callbacks the
     * behaviour is identical, so existing tests are unaffected; filter-contract
     * tests (AS-11+) need real dispatch.
     *
     * @param mixed $value
     * @param mixed ...$args
     * @return mixed
     */
    function apply_filters(string $tag, $value, ...$args)
    {
        $callbacks = $GLOBALS['wpas_test_filters'][$tag] ?? [];
        ksort($callbacks);
        foreach ($callbacks as $group) {
            foreach ($group as [$callback, $accepted_args]) {
                $value = $callback($value, ...array_slice($args, 0, max(0, $accepted_args - 1)));
            }
        }

        return $value;
    }
}

if (!function_exists('remove_all_filters')) {
    /** Test control knob: drop every callback for one hook (or all hooks). */
    function remove_all_filters(?string $hook = null): void
    {
        if ($hook === null) {
            $GLOBALS['wpas_test_filters'] = [];

            return;
        }
        unset($GLOBALS['wpas_test_filters'][$hook]);
    }
}

if (!function_exists('add_action')) {
    $GLOBALS['wpas_test_added_actions'] = [];

    /**
     * Recording shim: $GLOBALS['wpas_test_added_actions'][$hook][] = callback,
     * so a test can assert a hook was WIRED without a real WP action dispatcher.
     *
     * @param mixed ...$args
     */
    function add_action(...$args): bool
    {
        $GLOBALS['wpas_test_added_actions'][(string) $args[0]][] = $args[1] ?? null;

        return true;
    }
}

// Hook-removal + object-lookup shims used by the change-capture listener tests.
// add_action() above only records; these keep the same recording model.
if (!function_exists('remove_action')) {
    /** @param mixed $callback */
    function remove_action(string $hook, $callback, int $priority = 10): bool
    {
        $list = $GLOBALS['wpas_test_added_actions'][$hook] ?? [];
        foreach ($list as $i => $registered) {
            if ($registered === $callback) {
                unset($GLOBALS['wpas_test_added_actions'][$hook][$i]);
            }
        }

        return true;
    }
}

if (!function_exists('remove_filter')) {
    /** @param mixed $callback */
    function remove_filter(string $hook, $callback, int $priority = 10): bool
    {
        foreach ($GLOBALS['wpas_test_filters'][$hook][$priority] ?? [] as $i => [$registered]) {
            if ($registered === $callback) {
                unset($GLOBALS['wpas_test_filters'][$hook][$priority][$i]);
            }
        }

        return true;
    }
}

if (!function_exists('get_post')) {
    /** Test control knob: $GLOBALS['wpas_test_posts'][$id] holds the object/array. @param mixed $id @return mixed */
    function get_post($id)
    {
        return $GLOBALS['wpas_test_posts'][(int) $id] ?? null;
    }
}

if (!function_exists('get_term')) {
    /** @param mixed $id @return mixed */
    function get_term($id, string $taxonomy = '')
    {
        return $GLOBALS['wpas_test_terms'][(int) $id] ?? null;
    }
}

if (!function_exists('metadata_exists')) {
    function metadata_exists(string $type, int $id, string $key): bool
    {
        return array_key_exists($key, $GLOBALS['wpas_test_meta'][$id] ?? []);
    }
}

if (!function_exists('get_post_meta')) {
    /** @return mixed */
    function get_post_meta(int $id, string $key = '', bool $single = false)
    {
        return $GLOBALS['wpas_test_meta'][$id][$key] ?? '';
    }
}

if (!function_exists('is_multisite')) {
    /** Toggle shim: tests set $GLOBALS['wpas_test_multisite']. */
    function is_multisite(): bool
    {
        return !empty($GLOBALS['wpas_test_multisite']);
    }
}

if (!function_exists('plugin_basename')) {
    /** Identity-ish shim: tests don't depend on the exact shape. */
    function plugin_basename(string $file): string
    {
        return basename(dirname($file)) . '/' . basename($file);
    }
}

if (!function_exists('deactivate_plugins')) {
    $GLOBALS['wpas_test_deactivated_plugins'] = [];

    /**
     * Recording shim: $GLOBALS['wpas_test_deactivated_plugins'][].
     *
     * @param string|string[] $plugins
     */
    function deactivate_plugins($plugins): void
    {
        foreach ((array) $plugins as $plugin) {
            $GLOBALS['wpas_test_deactivated_plugins'][] = $plugin;
        }
    }
}

if (!function_exists('get_option')) {
    $GLOBALS['wpas_test_options'] = [];

    /**
     * Test control knob: set $GLOBALS['wpas_test_options'][$name] per-test.
     *
     * @param mixed $default
     * @return mixed
     */
    function get_option(string $name, $default = false)
    {
        return $GLOBALS['wpas_test_options'][$name] ?? $default;
    }
}

if (!function_exists('update_option')) {
    /**
     * Test control knob: mirrors writes into $GLOBALS['wpas_test_options'] so a
     * subsequent get_option() in the SAME test observes them.
     *
     * @param mixed $value
     */
    function update_option(string $name, $value, mixed $autoload = null): bool
    {
        $GLOBALS['wpas_test_options'][$name] = $value;

        return true;
    }
}

if (!function_exists('delete_option')) {
    function delete_option(string $name): bool
    {
        unset($GLOBALS['wpas_test_options'][$name]);

        return true;
    }
}

if (!class_exists('WP_User', false)) {
    /** Minimal stand-in: only the `ID` property the privacy tools read. */
    final class WP_User
    {
        public function __construct(public readonly int $ID)
        {
        }
    }
}

if (!function_exists('get_user_by')) {
    $GLOBALS['wpas_test_users_by_email'] = [];

    /**
     * Test control knob: set $GLOBALS['wpas_test_users_by_email'][$email] = $userId
     * per-test. Only the `email` field (the one the privacy tools use) is
     * honoured; anything else misses, like a real lookup on an unindexed field.
     *
     * @param mixed $value
     */
    function get_user_by(string $field, $value): WP_User|false
    {
        if ($field !== 'email') {
            return false;
        }

        $id = $GLOBALS['wpas_test_users_by_email'][$value] ?? null;

        return $id !== null ? new WP_User((int) $id) : false;
    }
}

if (!function_exists('wp_add_privacy_policy_content')) {
    $GLOBALS['wpas_test_privacy_policy_content'] = [];

    /**
     * Test control knob: recorded into $GLOBALS['wpas_test_privacy_policy_content'].
     */
    function wp_add_privacy_policy_content(string $pluginName, string $content): void
    {
        $GLOBALS['wpas_test_privacy_policy_content'][] = ['plugin_name' => $pluginName, 'content' => $content];
    }
}

if (!function_exists('add_option')) {
    /**
     * Test control knob: mirrors the real add_option()'s "only the FIRST
     * write wins" contract — the atomic lock AS-7's EnvironmentGuard relies
     * on — against the same $GLOBALS['wpas_test_options'] store.
     *
     * @param mixed $value
     */
    function add_option(string $name, $value = '', string $deprecated = '', $autoload = true): bool
    {
        if (array_key_exists($name, $GLOBALS['wpas_test_options'])) {
            return false;
        }

        $GLOBALS['wpas_test_options'][$name] = $value;

        return true;
    }
}

if (!function_exists('home_url')) {
    /** Test control knob: set $GLOBALS['wpas_test_home_url'] per-test; defaults to a stable host. */
    function home_url(string $path = ''): string
    {
        return ($GLOBALS['wpas_test_home_url'] ?? 'https://example.com') . $path;
    }
}

if (!function_exists('wp_get_environment_type')) {
    /**
     * Test control knob: set $GLOBALS['wpas_test_environment_type'] per-test.
     *
     * Deliberately defaults to 'local', NOT the real WordPress default of
     * 'production' (AS-7 §3.4 item 8) — every pre-stage-7 shadow-mode test
     * was written assuming the full 7-day {@see \Belayline\Plugin\Support\ShadowMode::MAX_TTL}
     * ceiling with no environment configured; defaulting this shim to
     * 'production' would silently reinterpret all of them under the new
     * 24-hour production ceiling. A test that wants the production heuristic
     * sets this global explicitly.
     */
    function wp_get_environment_type(): string
    {
        return $GLOBALS['wpas_test_environment_type'] ?? 'local';
    }
}

if (!function_exists('dbDelta')) {
    $GLOBALS['wpas_test_dbdelta_queries'] = [];

    /**
     * Test control knob: records every statement Schema::install() would have
     * handed to the real dbDelta() in $GLOBALS['wpas_test_dbdelta_queries'],
     * instead of diffing against a real database.
     *
     * @param string|list<string> $queries
     * @return array<string, string>
     */
    function dbDelta($queries = '', bool $execute = true): array
    {
        foreach ((array) $queries as $query) {
            $GLOBALS['wpas_test_dbdelta_queries'][] = $query;
        }

        return [];
    }
}

if (!function_exists('wp_next_scheduled')) {
    $GLOBALS['wpas_test_cron'] = [];

    /**
     * Test control knob: $GLOBALS['wpas_test_cron'][$hook] holds the next-run
     * timestamp, mirroring wp-cron's own option-backed schedule.
     *
     * @param array<int, mixed> $args
     */
    function wp_next_scheduled(string $hook, array $args = []): int|false
    {
        return $GLOBALS['wpas_test_cron'][$hook] ?? false;
    }
}

if (!function_exists('wp_schedule_event')) {
    /** @param array<int, mixed> $args */
    function wp_schedule_event(int $timestamp, string $recurrence, string $hook, array $args = []): bool
    {
        $GLOBALS['wpas_test_cron'][$hook] = $timestamp;

        return true;
    }
}

if (!function_exists('wp_clear_scheduled_hook')) {
    /** @param array<int, mixed> $args */
    function wp_clear_scheduled_hook(string $hook, array $args = []): int|false
    {
        $existed = isset($GLOBALS['wpas_test_cron'][$hook]);
        unset($GLOBALS['wpas_test_cron'][$hook]);

        return $existed ? 1 : false;
    }
}

if (!function_exists('is_wp_error')) {
    /** @param mixed $thing */
    function is_wp_error($thing): bool
    {
        // Safe even though WP_Error may not be defined here: instanceof against
        // an unknown class name simply evaluates to false, no autoload/fatal.
        return $thing instanceof \WP_Error;
    }
}

if (!function_exists('get_transient')) {
    $GLOBALS['wpas_test_transients'] = [];

    /**
     * Test control knob: $GLOBALS['wpas_test_transients'][$key] holds
     * ['value' => mixed, 'expires' => int|null], mirroring the real transients
     * API's options-table backing closely enough for RateCounter's needs.
     * Expiry is measured against the SAME $GLOBALS['wpas_test_time'] clock
     * Support\RateCounter reads (see stubs/wpas-clock.php), so a test that
     * freezes/advances time sees both agree.
     *
     * @return mixed
     */
    function get_transient(string $key)
    {
        $row = $GLOBALS['wpas_test_transients'][$key] ?? null;
        if ($row === null) {
            return false;
        }

        $now = $GLOBALS['wpas_test_time'] ?? time();
        if ($row['expires'] !== null && $row['expires'] <= $now) {
            unset($GLOBALS['wpas_test_transients'][$key]);

            return false;
        }

        return $row['value'];
    }
}

if (!function_exists('set_transient')) {
    /**
     * Test control knob: mirrors writes into $GLOBALS['wpas_test_transients']
     * so a subsequent get_transient() in the SAME test observes them (and their
     * expiry, per the $wpas_test_time clock).
     *
     * @param mixed $value
     */
    function set_transient(string $key, $value, int $expiration = 0): bool
    {
        $now = $GLOBALS['wpas_test_time'] ?? time();
        $GLOBALS['wpas_test_transients'][$key] = [
            'value' => $value,
            'expires' => $expiration > 0 ? $now + $expiration : null,
        ];

        return true;
    }
}

if (!function_exists('delete_transient')) {
    function delete_transient(string $key): bool
    {
        $existed = isset($GLOBALS['wpas_test_transients'][$key]);
        unset($GLOBALS['wpas_test_transients'][$key]);

        return $existed;
    }
}

if (!function_exists('do_action')) {
    $GLOBALS['wpas_test_actions'] = [];

    /**
     * Test control knob: fired actions are recorded into
     * $GLOBALS['wpas_test_actions'] as [hook, ...args] so a test can assert
     * exactly which hooks fired (and how often). Callbacks are NOT dispatched
     * — the add_action shim above is a no-op, matching how these tests drive
     * subscribers directly.
     *
     * @param mixed ...$args
     */
    function do_action(string $hook, ...$args): void
    {
        $GLOBALS['wpas_test_actions'][] = array_merge([$hook], $args);
    }
}

if (!function_exists('wp_mail')) {
    $GLOBALS['wpas_test_mail'] = [];

    /**
     * Test control knob: sent mail is recorded into $GLOBALS['wpas_test_mail']
     * as ['to' =>, 'subject' =>, 'message' =>].
     *
     * @param string|list<string> $to
     */
    function wp_mail($to, string $subject, string $message): bool
    {
        $GLOBALS['wpas_test_mail'][] = ['to' => $to, 'subject' => $subject, 'message' => $message];

        return true;
    }
}

if (!function_exists('wp_remote_post')) {
    $GLOBALS['wpas_test_http'] = [];

    /**
     * Test control knob: outgoing POSTs are recorded into
     * $GLOBALS['wpas_test_http'] as ['url' =>, 'args' =>].
     *
     * @param array<string, mixed> $args
     * @return array<string, mixed>
     */
    function wp_remote_post(string $url, array $args = []): array
    {
        $GLOBALS['wpas_test_http'][] = ['url' => $url, 'args' => $args];

        return [];
    }
}

if (!function_exists('admin_url')) {
    function admin_url(string $path = ''): string
    {
        return 'https://example.test/wp-admin/' . ltrim($path, '/');
    }
}

if (!function_exists('wp_create_nonce')) {
    function wp_create_nonce(string $action = '-1'): string
    {
        return 'test-nonce';
    }
}

if (!function_exists('add_query_arg')) {
    /** @param array<string, mixed> $args */
    function add_query_arg(array $args, string $url = ''): string
    {
        return $url . (str_contains($url, '?') ? '&' : '?') . http_build_query($args);
    }
}

if (!function_exists('add_management_page')) {
    /** @param mixed $callback */
    function add_management_page(string $title, string $menu, string $cap, string $slug, $callback = ''): string
    {
        $GLOBALS['wpas_test_management_pages'][$slug] = $cap;

        return $slug;
    }
}

if (!function_exists('add_menu_page')) {
    /** @param mixed $callback */
    function add_menu_page(string $title, string $menu, string $cap, string $slug, $callback = '', string $icon = '', $position = null): string
    {
        $GLOBALS['wpas_test_menu_pages'][$slug] = ['title' => $title, 'menu' => $menu, 'cap' => $cap, 'icon' => $icon, 'position' => $position, 'callback' => $callback];

        return 'toplevel_page_' . $slug;
    }
}

if (!function_exists('add_submenu_page')) {
    /** @param mixed $callback */
    function add_submenu_page(string $parent, string $title, string $menu, string $cap, string $slug, $callback = ''): string
    {
        $GLOBALS['wpas_test_submenu_pages'][$parent][$slug] = ['title' => $title, 'menu' => $menu, 'cap' => $cap, 'callback' => $callback];

        // Core: the first submenu item shares the parent's slug and hook; the rest are "<parent title slug>_page_<slug>".
        return $slug === $parent ? 'toplevel_page_' . $slug : $parent . '_page_' . $slug;
    }
}

if (!function_exists('wp_safe_redirect')) {
    function wp_safe_redirect(string $location, int $status = 302, string $xRedirectBy = 'WordPress'): bool
    {
        $GLOBALS['wpas_test_redirects'][] = ['location' => $location, 'status' => $status];

        throw new \Belayline\Plugin\Tests\Fakes\RedirectException($location, $status);
    }
}

if (!function_exists('wp_nonce_url')) {
    function wp_nonce_url(string $url, string $action = '-1', string $name = '_wpnonce'): string
    {
        return $url . (str_contains($url, '?') ? '&' : '?') . $name . '=' . wp_create_nonce($action);
    }
}

if (!function_exists('plugins_url')) {
    function plugins_url(string $path = '', string $plugin = ''): string
    {
        return 'https://example.test/wp-content/plugins/belayline-agent-governance/' . ltrim($path, '/');
    }
}

if (!function_exists('wp_enqueue_style')) {
    /** @param list<string> $deps */
    function wp_enqueue_style(string $handle, string $src = '', array $deps = [], $ver = false, string $media = 'all'): void
    {
        $GLOBALS['wpas_test_enqueued']['style'][$handle] = ['src' => $src, 'deps' => $deps, 'ver' => $ver];
    }
}

if (!function_exists('wp_enqueue_script')) {
    /** @param list<string> $deps */
    function wp_enqueue_script(string $handle, string $src = '', array $deps = [], $ver = false, $args = false): void
    {
        $GLOBALS['wpas_test_enqueued']['script'][$handle] = ['src' => $src, 'deps' => $deps, 'ver' => $ver];
    }
}

if (!function_exists('get_file_data')) {
    /** @param array<string, string> $headers */
    function get_file_data(string $file, array $headers, string $context = ''): array
    {
        $head = is_readable($file) ? (string) file_get_contents($file, false, null, 0, 8192) : '';
        $out = [];
        foreach ($headers as $key => $label) {
            $out[$key] = preg_match('/^[ \t\/*#@]*' . preg_quote($label, '/') . ':(.*)$/mi', $head, $m) === 1 ? trim($m[1]) : '';
        }

        return $out;
    }
}

if (!function_exists('get_userdata')) {
    function get_userdata(int $id): object|false
    {
        $name = $GLOBALS['wpas_test_user_names'][$id] ?? null;

        return $name === null ? false : (object) ['ID' => $id, 'display_name' => $name];
    }
}

if (!function_exists('wp_die')) {
    /**
     * Throws instead of exiting, so a test can assert that an admin_post
     * handler stopped at its capability or nonce check before touching state.
     *
     * @param mixed $message
     * @param mixed $title
     * @param mixed $args
     */
    function wp_die($message = '', $title = '', $args = []): never
    {
        throw new RuntimeException('wp_die: ' . (is_string($message) ? $message : ''));
    }
}

if (!function_exists('rest_get_authenticated_app_password')) {
    /**
     * Core returns the UUID of the application password that authenticated the
     * current request, or null; tests set `$GLOBALS['wpas_test_app_password']`.
     */
    function rest_get_authenticated_app_password(): ?string
    {
        $uuid = $GLOBALS['wpas_test_app_password'] ?? null;

        return is_string($uuid) ? $uuid : null;
    }
}

if (!function_exists('check_admin_referer')) {
    /**
     * Accepts exactly the value the wp_nonce_field shim above emits, read from
     * $_POST or $_GET (CLI never rebuilds $_REQUEST); anything else dies, as
     * core does.
     */
    function check_admin_referer(string $action = '-1', string $query_arg = '_wpnonce'): int
    {
        $nonce = $_POST[$query_arg] ?? $_GET[$query_arg] ?? null;
        if ($nonce !== 'test-nonce') {
            wp_die('The link you followed has expired.');
        }

        return 1;
    }
}

if (!function_exists('wp_unslash')) {
    /**
     * @param mixed $value
     * @return mixed
     */
    function wp_unslash($value)
    {
        if (is_array($value)) {
            return array_map('wp_unslash', $value);
        }

        return is_string($value) ? stripslashes($value) : $value;
    }
}

if (!function_exists('wpas_test_kses_protocol_ok')) {
    /**
     * The shim's stand-in for core's wp_kses_bad_protocol(): is this URL-bearing
     * attribute value safe to keep?
     *
     * Core normalises before it compares a scheme, and so must this shim — a
     * shim that waves `javascript:` through would let the summary-cell tests
     * pass while the real screen is still injectable, which is exactly the
     * failure this harness exists to catch. Normalisation covers the tricks a
     * browser itself tolerates: entity-encoded colons (`&#58;`, `&#x3a;`,
     * `&colon;`), leading/embedded whitespace and control characters
     * (`java\tscript:`), and scheme case (`JaVaScRiPt:`).
     *
     * Relative, root-relative, query-only and anchor-only URLs have no scheme
     * and are kept. Anything with a scheme outside the allow-list is REJECTED —
     * the shim drops the whole attribute where core strips just the scheme;
     * stricter than core, and never the other way round.
     */
    function wpas_test_kses_protocol_ok(string $value): bool
    {
        $safe_protocols = ['http', 'https', 'mailto'];

        // Decode the colon tricks first (with or without the closing semicolon,
        // which browsers tolerate), then html_entity_decode for the rest.
        $normalised = preg_replace(['/&#0*58;?/i', '/&#x0*3a;?/i'], ':', $value);
        if (!is_string($normalised)) {
            return false; // Fail closed: could not normalise, so cannot vouch for it.
        }
        $normalised = str_ireplace('&colon;', ':', $normalised);
        $normalised = html_entity_decode($normalised, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        // Whitespace and control characters are ignored by browsers inside a scheme.
        $normalised = preg_replace('/[\x00-\x20\x7F]+/', '', $normalised);
        if (!is_string($normalised)) {
            return false; // Fail closed.
        }

        if ($normalised === '') {
            return true; // An empty href is inert.
        }
        if (!preg_match('#^([a-zA-Z][a-zA-Z0-9+.\-]*):#', $normalised, $scheme)) {
            return true; // No scheme at all: relative / root-relative / `?` / `#`.
        }

        return in_array(strtolower($scheme[1]), $safe_protocols, true);
    }
}

if (!function_exists('wp_kses')) {
    /**
     * SHIM for the summary-cell contract (AS-11): strips every tag not in the
     * allow-list, every attribute not allowed for its tag, and every URL-bearing
     * attribute whose protocol is not in the safe allow-list (see
     * {@see wpas_test_kses_protocol_ok()}). Minimal stand-in - the REAL
     * sanitisation check is a wp-env smoke against core's wp_kses; this shim
     * only locks the call shape and the protocol contract so the unit suite can
     * exercise the render path.
     *
     * @param string               $string       Content to sanitise.
     * @param array<string, mixed> $allowed_html Allowed tags => allowed attributes.
     */
    function wp_kses(string $string, array $allowed_html = []): string
    {
        if (($logFile = getenv('BELAYLINE_KSES_LOG')) !== false && $logFile !== '') {
            // Opt-in: record every input so it can be replayed through core's real kses.
            file_put_contents($logFile, base64_encode($string) . "\n", FILE_APPEND);
        }

        $allowed_tags = array_keys($allowed_html);
        $url_attributes = ['href', 'src', 'action', 'formaction', 'xlink:href', 'background', 'cite', 'longdesc'];

        $result = preg_replace_callback(
            '/<\s*\/?\s*([a-zA-Z0-9]+)((?:[^>"\']|"[^"]*"|\'[^\']*\')*)>/',
            static function (array $m) use ($allowed_tags, $allowed_html, $url_attributes): string {
                $closing = str_starts_with(ltrim($m[0]), '</');
                $tag = strtolower($m[1]);
                if (!in_array($tag, $allowed_tags, true)) {
                    return ''; // Not allowed: the whole element goes.
                }
                if ($closing) {
                    return '</' . $tag . '>';
                }
                // Like core: walk the attributes in SOURCE order, keep the allowed ones,
                // lower-case names, normalise to double quotes, space before a self-close.
                $attrs = array_change_key_case($allowed_html[$tag] ?? [], CASE_LOWER);
                $kept = '';
                preg_match_all('/([a-zA-Z_:][-a-zA-Z0-9_:.]*)(?:\s*=\s*("[^"]*"|\'[^\']*\'|[^\s"\'>\/]+))?/', $m[2], $found, PREG_SET_ORDER);
                foreach ($found as $am) {
                    $attr_name = strtolower($am[1]);
                    if (empty($attrs[$attr_name])) {
                        continue;
                    }
                    if (!isset($am[2])) {
                        $kept .= ' ' . $attr_name; // Boolean attribute.
                        continue;
                    }
                    $raw = $am[2];
                    if (strlen($raw) >= 2 && ($raw[0] === '"' || $raw[0] === "'") && $raw[-1] === $raw[0]) {
                        $raw = substr($raw, 1, -1);
                    }
                    if (in_array($attr_name, $url_attributes, true) && !wpas_test_kses_protocol_ok($raw)) {
                        continue; // Bad protocol: drop the attribute entirely.
                    }
                    $kept .= ' ' . $attr_name . '="' . $raw . '"';
                }

                return '<' . $tag . $kept . (preg_match('/\/\s*$/', $m[2]) ? ' /' : '') . '>';
            },
            $string
        );

        return is_string($result) ? $result : $string;
    }
}

if (!function_exists('wp_print_inline_script_tag')) {
    function wp_print_inline_script_tag(string $data, array $attributes = []): void
    {
        echo '<script>' . $data . "</script>\n";
    }
}

if (!function_exists('esc_html')) {
    function esc_html(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

if (!function_exists('esc_attr')) {
    function esc_attr(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

if (!function_exists('esc_url')) {
    function esc_url(string $url): string
    {
        return htmlspecialchars($url, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

if (!function_exists('esc_html__')) {
    function esc_html__(string $text, string $domain = 'default'): string
    {
        return esc_html(__($text, $domain));
    }
}

if (!function_exists('esc_attr__')) {
    function esc_attr__(string $text, string $domain = 'default'): string
    {
        return esc_attr(__($text, $domain));
    }
}

if (!function_exists('wp_nonce_field')) {
    /** @return string|null Echoes when $echo, mirroring core's signature. */
    function wp_nonce_field(
        string $action = '-1',
        string $name = '_wpnonce',
        bool $referer = true,
        bool $echo = true
    ): ?string {
        $field = '<input type="hidden" name="' . esc_attr($name) . '" value="test-nonce">';
        if ($echo) {
            echo $field; // phpcs:ignore WordPress.Security.EscapeOutput -- test shim.

            return null;
        }

        return $field;
    }
}

if (!function_exists('wp_json_encode')) {
    /**
     * @param mixed $data
     * @return string|false
     */
    function wp_json_encode($data)
    {
        return json_encode($data);
    }
}

if (!function_exists('absint')) {
    /** @param mixed $value */
    function absint($value): int
    {
        return abs((int) $value);
    }
}

if (!function_exists('sanitize_text_field')) {
    /** Enough of core's behaviour for form-value tests: tags and surrounding whitespace go. */
    function sanitize_text_field(string $str): string
    {
        return trim(strip_tags($str));
    }
}

if (!function_exists('sanitize_textarea_field')) {
    function sanitize_textarea_field(string $str): string
    {
        return trim(strip_tags($str));
    }
}

if (!function_exists('sanitize_key')) {
    function sanitize_key(string $key): string
    {
        return preg_replace('/[^a-z0-9_\-]/', '', strtolower($key)) ?? '';
    }
}

if (!function_exists('esc_textarea')) {
    function esc_textarea(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

if (!function_exists('sanitize_email')) {
    /** Enough for the FluentCart snapshotter tests: trim and lowercase-agnostic, empty when not an address. */
    function sanitize_email(string $email): string
    {
        $email = trim($email);

        return filter_var($email, FILTER_VALIDATE_EMAIL) !== false ? $email : '';
    }
}

if (!function_exists('checked')) {
    /** @param mixed $checked @param mixed $current */
    function checked($checked, $current = true, bool $display = true): string
    {
        $out = (string) $checked === (string) $current ? ' checked=\'checked\'' : '';
        if ($display) {
            echo $out; // phpcs:ignore WordPress.Security.EscapeOutput -- test shim.
        }

        return $out;
    }
}

if (!function_exists('selected')) {
    /** @param mixed $selected @param mixed $current */
    function selected($selected, $current = true, bool $display = true): string
    {
        $out = (string) $selected === (string) $current ? ' selected=\'selected\'' : '';
        if ($display) {
            echo $out; // phpcs:ignore WordPress.Security.EscapeOutput -- test shim.
        }

        return $out;
    }
}

if (!function_exists('_n')) {
    function _n(string $single, string $plural, int $number, string $domain = 'default'): string
    {
        return $number === 1 ? $single : $plural;
    }
}

if (!function_exists('submit_button')) {
    /** @param mixed $type @param mixed $otherAttributes */
    function submit_button(
        ?string $text = null,
        $type = 'primary',
        string $name = 'submit',
        bool $wrap = true,
        $otherAttributes = null
    ): void {
        echo '<button type="submit" name="' . esc_attr($name) . '">' . esc_html((string) $text) . '</button>';
    }
}

// --- Abilities API shims (AS-8) -------------------------------------------
// No real WP Abilities API is available in this suite. These shims record
// what production code registers, closely enough for SelfIntegrationTest to
// assert the args shape and to invoke the recorded callbacks directly — the
// same house style as add_action()/add_filter() above.

if (!function_exists('doing_action')) {
    $GLOBALS['wpas_test_doing_action'] = null;

    /** Test control knob: set $GLOBALS['wpas_test_doing_action'] to the hook name currently "firing". */
    function doing_action(?string $hook = null): bool
    {
        if ($hook === null) {
            return $GLOBALS['wpas_test_doing_action'] !== null;
        }

        return $GLOBALS['wpas_test_doing_action'] === $hook;
    }
}

if (!function_exists('wp_register_ability')) {
    $GLOBALS['wpas_test_abilities'] = [];

    /**
     * Recording shim: $GLOBALS['wpas_test_abilities'][$name] = $args.
     *
     * @param array<string, mixed> $args
     * @return array<string, mixed>
     */
    function wp_register_ability(string $name, array $args): array
    {
        $GLOBALS['wpas_test_abilities'][$name] = $args;

        return $args;
    }
}

require_once __DIR__ . '/stubs/wp-ability.php';

if (!function_exists('wp_get_ability')) {
    /** Reads what wp_register_ability() recorded; null when the ability is not registered. */
    function wp_get_ability(string $name): ?object
    {
        $args = $GLOBALS['wpas_test_abilities'][$name] ?? null;
        if (!is_array($args)) {
            return null;
        }

        return new WP_Ability((string) ($args['label'] ?? ''));
    }
}

if (!function_exists('wp_register_ability_category')) {
    $GLOBALS['wpas_test_ability_categories'] = [];

    /**
     * Recording shim: $GLOBALS['wpas_test_ability_categories'][$slug] = $args.
     *
     * @param array<string, mixed> $args
     * @return array<string, mixed>
     */
    function wp_register_ability_category(string $slug, array $args): array
    {
        $GLOBALS['wpas_test_ability_categories'][$slug] = $args;

        return $args;
    }
}

// --- Write shims for the undo engine ---------------------------------------
// Minimal WordPress write behaviour over the same $GLOBALS stores get_post() /
// get_term() / get_post_meta() read, DISPATCHING the hooks the capture adapters
// listen on (the add_action shim records callbacks, so we invoke them here).

if (!function_exists('wpas_test_fire')) {
    /** @param mixed ...$args */
    function wpas_test_fire(string $hook, ...$args): void
    {
        foreach ($GLOBALS['wpas_test_added_actions'][$hook] ?? [] as $callback) {
            if (is_callable($callback)) {
                $callback(...$args);
            }
        }
    }
}

if (!function_exists('wp_slash')) {
    /**
     * @param mixed $value
     * @return mixed
     */
    function wp_slash($value)
    {
        if (is_array($value)) {
            return array_map('wp_slash', $value);
        }

        return is_string($value) ? addslashes($value) : $value;
    }
}

if (!function_exists('wp_update_post')) {
    /**
     * @param array<string, mixed> $data slashed
     * @return int|\WP_Error
     */
    function wp_update_post(array $data, bool $wp_error = false)
    {
        $data = wp_unslash($data);
        $id = (int) ($data['ID'] ?? 0);
        $old = $GLOBALS['wpas_test_posts'][$id] ?? null;
        if ($old === null) {
            return 0;
        }
        $oldArr = (array) $old;
        $newArr = array_merge($oldArr, $data, ['post_modified' => gmdate('Y-m-d H:i:s', 1000000000 + (int) ($GLOBALS['wpas_test_clock'] = ($GLOBALS['wpas_test_clock'] ?? 0) + 1))]);
        $GLOBALS['wpas_test_posts'][$id] = (object) $newArr;
        wpas_test_fire('post_updated', $id, (object) $newArr, (object) $oldArr);

        return $id;
    }
}

if (!function_exists('wp_trash_post')) {
    /** @return mixed */
    function wp_trash_post(int $id)
    {
        $post = $GLOBALS['wpas_test_posts'][$id] ?? null;
        if ($post === null || ($post->post_status ?? '') === 'trash') {
            return false;
        }
        wpas_test_fire('wp_trash_post', $id, (string) $post->post_status);
        wp_update_post(wp_slash(['ID' => $id, 'post_status' => 'trash']));
        $GLOBALS['wpas_test_trash_status'][$id] = (string) $post->post_status;

        return $GLOBALS['wpas_test_posts'][$id];
    }
}

if (!function_exists('wp_untrash_post')) {
    /** @return mixed */
    function wp_untrash_post(int $id)
    {
        // Like core >= 5.6: back to draft unless a filter says otherwise.
        wp_update_post(wp_slash(['ID' => $id, 'post_status' => 'draft']));

        return $GLOBALS['wpas_test_posts'][$id] ?? false;
    }
}

if (!function_exists('update_post_meta')) {
    /**
     * @param mixed $value slashed
     * @return mixed
     */
    function update_post_meta(int $id, string $key, $value)
    {
        $value = wp_unslash($value);
        $check = apply_filters('update_post_metadata', null, $id, $key, $value, '');
        if ($check !== null) {
            return (bool) $check;
        }
        $GLOBALS['wpas_test_meta'][$id][$key] = $value;

        return true;
    }
}

if (!function_exists('delete_post_meta')) {
    function delete_post_meta(int $id, string $key): bool
    {
        unset($GLOBALS['wpas_test_meta'][$id][$key]);

        return true;
    }
}

if (!function_exists('wp_update_term')) {
    /**
     * @param array<string, mixed> $args slashed
     * @return mixed
     */
    function wp_update_term(int $id, string $taxonomy, array $args)
    {
        $old = $GLOBALS['wpas_test_terms'][$id] ?? null;
        if ($old === null) {
            return new \WP_Error('invalid_term', 'missing');
        }
        wpas_test_fire('edit_terms', $id, $taxonomy);
        $GLOBALS['wpas_test_terms'][$id] = (object) array_merge((array) $old, wp_unslash($args));
        wpas_test_fire('edited_terms', $id, $taxonomy);

        return ['term_id' => $id];
    }
}
