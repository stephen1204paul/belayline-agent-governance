<?php

declare(strict_types=1);

namespace Belayline\Plugin\Admin;

use Belayline\Plugin\Admin\Ui\Icons;
use Belayline\Plugin\Admin\Ui\Shell;
use Belayline\Plugin\Audit\WpdbApprovalStore;
use Belayline\Plugin\Support\PauseSwitch;
use Belayline\Plugin\Support\ShadowMode;

/**
 * Belayline's wp-admin presence: ONE top-level menu with five tabs as submenus
 * (Overview, Pending, Activity, Packs, Settings), the kit assets loaded only on
 * those screens, and the redirects that keep every old `tools.php?page=...` URL
 * working. The tabs' content comes from the page classes, which also own the
 * admin-post handlers; nothing here makes or changes a governance decision.
 */
final class AdminApp
{
    public const CAP = 'manage_options';
    public const MENU_POSITION = 76; // right after Tools (75), before Settings (80).

    /** @var array<string, string> tab => the hook suffix WordPress gave its screen. */
    private array $hooks = [];

    private ?Shell $shell = null;

    public function __construct(
        private readonly string $pluginFile,
        private readonly PauseSwitch $pause,
        private readonly ShadowMode $shadow,
        private readonly ?WpdbApprovalStore $approvals,
        private readonly CapabilityPacksPage $packs,
        private readonly SettingsPage $settings,
        private readonly ?OverviewPage $overview = null,
        private readonly ?PendingActionsPage $pending = null,
        private readonly ?AuditLogPage $audit = null,
        private readonly ?ChangesPage $changes = null,
        private readonly ?AbilitiesPage $abilities = null,
    ) {
    }

    public function register(): void
    {
        add_action('admin_menu', [$this, 'menu']);
        add_action('admin_init', [$this, 'redirectLegacy']);
        add_action('admin_enqueue_scripts', [$this, 'assets']);
    }

    public function menu(): void
    {
        $labels = Shell::tabLabels();
        $pending = $this->approvals?->pendingCount() ?? 0;

        $this->hooks[AdminUrls::OVERVIEW] = (string) add_menu_page(
            __('Belayline', 'belayline-agent-governance'),
            __('Belayline', 'belayline-agent-governance'),
            self::CAP,
            AdminUrls::SLUGS[AdminUrls::OVERVIEW],
            fn () => $this->renderTab(AdminUrls::OVERVIEW),
            self::menuIcon(),
            self::MENU_POSITION
        );

        foreach ($labels as $tab => $label) {
            $menuTitle = esc_html($label);
            if ($tab === AdminUrls::PENDING && $pending > 0) {
                // The same bubble core uses for update counts.
                $menuTitle .= ' <span class="awaiting-mod count-' . (int) $pending . '"><span class="pending-count" aria-hidden="true">' . (int) $pending
                    . '</span><span class="screen-reader-text">' . esc_html(sprintf(
                        /* translators: %d: number of actions waiting for approval */
                        _n('%d action waiting', '%d actions waiting', $pending, 'belayline-agent-governance'),
                        $pending
                    )) . '</span></span>';
            }
            $this->hooks[$tab] = (string) add_submenu_page(
                AdminUrls::SLUGS[AdminUrls::OVERVIEW],
                $label,
                $menuTitle,
                self::CAP,
                AdminUrls::SLUGS[$tab],
                fn () => $this->renderTab($tab)
            );
        }
    }

    /**
     * The menu icon: a small shield with a rope loop, as an SVG data URI. The
     * fill sits on the root element, which is where WordPress's menu-icon painter
     * recolours it for each admin color scheme.
     */
    public static function menuIcon(): string
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="#a7aaad"><path fill-rule="evenodd" d="'
            . 'M10 1.5 17 4v5.5c0 4.3-3 7.5-7 9-4-1.5-7-4.7-7-9V4zM10 5.6a2.6 2.6 0 1 0 0 5.2 2.6 2.6 0 0 0 0-5.2zM9.3 10.8h1.4v4.9H9.3z"/></svg>';

        return 'data:image/svg+xml;base64,' . base64_encode($svg);
    }

    /** Load the kit only on Belayline's own screens. */
    public function assets(string $hook): void
    {
        $tab = array_search($hook, $this->hooks, true);
        if ($tab === false) {
            return;
        }

        $kit = plugins_url('assets/vendor/admin-ui-kit/', $this->pluginFile);
        $dir = Icons::kitDir();
        $kitVersion = is_readable($dir . '/VERSION') ? trim((string) file_get_contents($dir . '/VERSION')) : '0';
        $own = plugins_url('assets/', $this->pluginFile);
        $version = $this->version();

        wp_enqueue_style('belayline-aui-tokens', $kit . 'tokens.css', [], $kitVersion);
        wp_enqueue_style('belayline-aui', $kit . 'components.css', ['belayline-aui-tokens'], $kitVersion);
        wp_enqueue_style('belayline-admin', $own . 'belayline-admin.css', ['belayline-aui'], $version);
        wp_enqueue_script('belayline-aui', $kit . 'aui.js', [], $kitVersion, true);
        $deps = ['belayline-aui'];
        if ($tab === AdminUrls::OVERVIEW) {
            wp_enqueue_script('belayline-aui-map', $kit . 'aui-map.js', ['belayline-aui'], $kitVersion, true);
            $deps[] = 'belayline-aui-map';
        }
        wp_enqueue_script('belayline-admin', $own . 'belayline-admin.js', $deps, $version, true);
    }

    /**
     * The tab a legacy `tools.php?page=belayline-*` request maps to, with every
     * other query argument carried over, or null when the request is not one of
     * the old pages.
     *
     * @param array<string, mixed> $get Unslashed $_GET.
     */
    public function legacyTarget(array $get, string $pagenow): ?string
    {
        if ($pagenow !== 'tools.php') {
            return null;
        }
        $page = isset($get['page']) && is_scalar($get['page']) ? sanitize_key((string) $get['page']) : '';
        if (!isset(AdminUrls::LEGACY[$page])) {
            return null;
        }
        [$tab, $extra, $fragment] = AdminUrls::LEGACY[$page];
        unset($get['page']);

        return AdminUrls::tab($tab, $extra + $get, $fragment);
    }

    /** admin_init runs before WordPress decides the unregistered old page is forbidden. */
    public function redirectLegacy(): void
    {
        global $pagenow;
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a navigation redirect; every argument is passed through untouched to the page that validates it.
        $url = $this->legacyTarget(wp_unslash($_GET), is_string($pagenow) ? $pagenow : '');
        if ($url === null) {
            return;
        }
        wp_safe_redirect($url, 302);
        exit;
    }

    private function version(): string
    {
        $data = function_exists('get_file_data') ? get_file_data($this->pluginFile, ['Version' => 'Version']) : [];

        return isset($data['Version']) && $data['Version'] !== '' ? $data['Version'] : '0';
    }

    private function shell(): Shell
    {
        return $this->shell ??= new Shell($this->pause, $this->shadow, $this->approvals, $this->version());
    }

    /** Render one tab inside the shell. WordPress has already checked the capability for the menu item. */
    public function renderTab(string $tab): void
    {
        if (!current_user_can(self::CAP)) {
            return;
        }

        switch ($tab) {
            case AdminUrls::PENDING:
                $this->shell()->render(
                    $tab,
                    fn () => $this->pending !== null ? $this->pending->renderMain() : $this->unavailable(),
                    fn () => $this->pending?->renderRail()
                );
                break;
            case AdminUrls::ACTIVITY:
                $this->shell()->render($tab, fn () => $this->activity());
                break;
            case AdminUrls::PACKS:
                $this->shell()->render($tab, function (): void {
                    $this->packs->renderMain();
                    $this->abilities?->renderMain();
                    $this->packs->renderBindingsCard();
                }, fn () => $this->packs->renderRail());
                break;
            case AdminUrls::SETTINGS:
                $this->shell()->render($tab, fn () => $this->settings->renderMain());
                break;
            default:
                $this->shell()->render(
                    AdminUrls::OVERVIEW,
                    fn () => $this->overview !== null ? $this->overview->renderMain() : $this->unavailable(),
                    fn () => $this->overview?->renderRail()
                );
        }
    }

    /** Activity has two views, Calls and Changes, switched with links so each has its own URL. */
    private function activity(): void
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- selects a read-only view.
        $section = isset($_GET['section']) && is_scalar($_GET['section']) ? sanitize_key((string) $_GET['section']) : AdminUrls::CALLS;
        $section = $section === AdminUrls::CHANGES ? AdminUrls::CHANGES : AdminUrls::CALLS;

        echo '<nav class="aui-seg belayline-subnav" aria-label="' . esc_attr__('Activity views', 'belayline-agent-governance') . '">';
        foreach ([AdminUrls::CALLS => __('Calls', 'belayline-agent-governance'), AdminUrls::CHANGES => __('Changes', 'belayline-agent-governance')] as $key => $label) {
            echo '<a href="' . esc_url(AdminUrls::activity($key)) . '"' . ($key === $section ? ' aria-current="page"' : '') . '>' . esc_html($label) . '</a>';
        }
        echo '</nav>';

        if ($section === AdminUrls::CHANGES) {
            $this->changes !== null ? $this->changes->renderMain() : $this->unavailable();

            return;
        }
        $this->audit !== null ? $this->audit->renderMain() : $this->unavailable();
    }

    /** Shown for a tab whose store is missing (the no-database path): governed calls fail closed there too. */
    private function unavailable(): void
    {
        echo '<section class="aui-card"><div class="aui-empty"><span class="aui-tile aui-tile--bad">' . Icons::svg('warning', 'aui-i--lg') . '</span><h2 class="aui-card-t">' // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Icons::svg() escapes its own parts.
            . esc_html__('This screen is not available', 'belayline-agent-governance') . '</h2><p>'
            . esc_html__('Belayline could not reach its database tables, so there is nothing to show here. Agent calls that need them are refused until this is fixed.', 'belayline-agent-governance')
            . '</p></div></section>';
    }
}
