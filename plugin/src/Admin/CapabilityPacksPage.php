<?php

declare(strict_types=1);

namespace Belayline\Plugin\Admin;

use Belayline\Packs\Pack;
use Belayline\Plugin\Admin\Map\PackLabel;
use Belayline\Plugin\Admin\Ui\Html;
use Belayline\Plugin\Admin\Ui\Icons;
use Belayline\Plugin\Admin\Ui\Tiers;
use Belayline\Plugin\Identity\IdentityChain;
use Belayline\Plugin\Identity\IdentityProvider;
use Belayline\Plugin\Support\AdminChangeRecorder;
use Belayline\Plugin\Support\EnvironmentGuard;
use Belayline\Plugin\Support\PackResolver;
use Belayline\Plugin\Support\PauseSwitch;
use Belayline\Plugin\Support\IpAllowlist;
use Belayline\Plugin\Support\ShadowMode;

/**
 * The Packs tab (and the environment and IP cards of the Settings tab): the
 * human side of credential scoping, and the handlers behind the emergency stop
 * that overrides all of it.
 *
 * Shows the pack catalog (read-only) and lets an admin bind each identity a
 * configured {@see IdentityProvider} exposes (an application password, a user,
 * a role, or an integration's own credential — e.g. a WooCommerce API key) to
 * a pack from the catalog. One section per provider, using its {@see
 * IdentityProvider::label()} and {@see IdentityProvider::bindableTokens()}.
 * Bindings persist in the `belayline_pack_bindings` option (token id => pack name)
 * that {@see PackResolver} reads per request; an unbound token falls back to
 * the safe default pack.
 *
 * A pack with `deny_class: ["tier2"]` makes every irreversible verb unreachable
 * for the bound credential BY CONSTRUCTION — the gate denies it before approval,
 * so prompt injection cannot reach it.
 *
 * Every change saved here is written to the audit chain through
 * {@see AdminChangeRecorder}, one row per binding or pack that actually
 * changed: the log must be able to say who widened a credential or switched
 * enforcement off, not only what the agent did afterwards.
 *
 * The emergency stop ({@see PauseSwitch}) sits at the top of every tab's right
 * rail ({@see Ui\Shell}) and, while it holds, as a banner on every wp-admin
 * screen: an operator who has just noticed an agent misbehaving must not have to
 * find this page to stop it. This class owns its two admin-post handlers.
 */
final class CapabilityPacksPage
{
    private const CAP = 'manage_options';
    private const SAVE = 'belayline_save_pack_bindings';
    private const SHADOW = 'belayline_save_shadow_packs';
    public const PAUSE = 'belayline_pause';
    public const RESUME = 'belayline_resume';
    private const REBIND = 'belayline_rebind_environment';
    private const RENEW_SHADOW = 'belayline_renew_shadow_pack';
    private const IP = 'belayline_save_ip_allowlist';

    /** DOM id of the row a deep link asked to bind; the page scrolls to and highlights it. */
    private const PREFILL_ROW = 'belayline-bind-prefill';

    /** Rows listed per pack before "n more". */
    private const PACK_ROWS = 12;

    /** Observation windows offered on the shadow form, in days; the last is the default. */
    private const SHADOW_DAYS = [1, 3, 7];

    public function __construct(
        private readonly PackResolver $packs,
        private readonly IdentityChain $identity,
        private readonly ShadowMode $shadow = new ShadowMode(),
        private readonly AdminChangeRecorder $changes = new AdminChangeRecorder(),
        private readonly PauseSwitch $pause = new PauseSwitch(),
        private readonly ?EnvironmentGuard $environment = null,
        private readonly IpAllowlist $ip = new IpAllowlist(),
        private readonly ?PackOverview $overview = null,
    ) {
    }

    public function register(): void
    {
        add_action('admin_post_' . self::SAVE, [$this, 'save']);
        add_action('admin_post_' . self::SHADOW, [$this, 'saveShadow']);
        add_action('admin_post_' . self::PAUSE, [$this, 'pauseAction']);
        add_action('admin_post_' . self::RESUME, [$this, 'resumeAction']);
        add_action('admin_post_' . self::REBIND, [$this, 'rebindAction']);
        add_action('admin_post_' . self::RENEW_SHADOW, [$this, 'renewShadowAction']);
        add_action('admin_post_' . self::IP, [$this, 'ipAllowlistAction']);
        add_action('admin_notices', [$this, 'pausedNotice']);
    }

    public function render(): void
    {
        if (!current_user_can(self::CAP)) {
            return;
        }
        $this->renderMain();
    }

    /** The Packs tab, minus the abilities card (which {@see AbilitiesPage} renders): the catalog, then the bindings. */
    public function renderMain(): void
    {
        $registry = $this->packs->registry();

        echo '<p class="aui-page-intro">' . esc_html__('Packs decide what each agent may do. Anything not in a pack is refused. Choose how each ability behaves, and which roles, people and keys use which pack.', 'belayline-agent-governance') . '</p>';

        if (isset($_GET['belayline_saved']) && $_GET['belayline_saved'] === '1') { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only flash.
            echo Html::notice('success', __('Saved.', 'belayline-agent-governance'), __('The change applies on the next agent call.', 'belayline-agent-governance')); // phpcs:ignore WordPress.Security.EscapeOutput -- Html::notice() escapes.
        }

        $this->renderCatalog($registry->names());
    }

    /** The bindings card, rendered after the abilities card by the Packs tab. */
    public function renderBindingsCard(): void
    {
        $registry = $this->packs->registry();
        $this->renderBindings($registry->names(), $registry->defaultPack(), $registry->bindings(), $this->bindPrefill($registry->names()));
    }

    /** Rail widget: who is bound to which pack right now. */
    public function renderRail(): void
    {
        $registry = $this->packs->registry();
        $labels = [];
        foreach ($this->identity->providers() as $provider) {
            foreach ($provider->bindableTokens() as $token => $label) {
                $labels[(string) $token] = $label;
            }
        }

        echo '<section class="aui-card"><div class="aui-card-h"><h2 class="aui-card-t">' . esc_html__('Who uses these packs', 'belayline-agent-governance') . '</h2></div><div class="aui-card-b">';
        $bindings = $registry->bindings();
        if ($bindings === []) {
            echo '<p class="aui-muted">' . esc_html(sprintf(
                /* translators: %s: pack name */
                __('Nobody is bound to a pack yet, so everyone uses the %s pack.', 'belayline-agent-governance'),
                PackLabel::of($registry->defaultPack())
            )) . '</p></div></section>';

            return;
        }
        echo '<ul class="aui-feed">';
        foreach ($bindings as $token => $pack) {
            $icon = str_starts_with((string) $token, 'user:') || str_starts_with((string) $token, 'role:') ? 'user' : 'key';
            echo '<li>' . Icons::svg($icon) . '<div>' . esc_html($labels[(string) $token] ?? (string) $token) . '<time>' . esc_html(PackLabel::of($pack)) . '</time></div></li>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Icons::svg() escapes its own parts.
        }
        echo '</ul><p class="aui-help">' . esc_html(sprintf(
            /* translators: %s: pack name */
            __('Everyone else uses the %s pack.', 'belayline-agent-governance'),
            PackLabel::of($registry->defaultPack())
        )) . '</p></div></section>';
    }

    /**
     * Read-only deep-link pre-fill: `belayline_bind_token` + `belayline_bind_pack`
     * select a pack in a row's dropdown for the admin to review. Both must
     * match something this page lists (a token an identity provider offers, a
     * registered pack); anything else is ignored silently. Nothing is
     * persisted from the GET — the existing POST + nonce save is the only
     * writer.
     *
     * @param  list<string>                                         $names
     * @return array{token: string, pack: string, label: string}|null
     */
    private function bindPrefill(array $names): ?array
    {
        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- display-only pre-fill; validated against the listed tokens/packs and never saved from GET.
        $token = isset($_GET['belayline_bind_token']) && is_string($_GET['belayline_bind_token'])
            ? sanitize_text_field(wp_unslash($_GET['belayline_bind_token'])) : '';
        $pack = isset($_GET['belayline_bind_pack']) && is_string($_GET['belayline_bind_pack'])
            ? sanitize_text_field(wp_unslash($_GET['belayline_bind_pack'])) : '';
        // phpcs:enable WordPress.Security.NonceVerification.Recommended

        if ($token === '' || $pack === '' || !in_array($pack, $names, true)) {
            return null;
        }

        foreach ($this->identity->providers() as $provider) {
            $tokens = $provider->bindableTokens();
            if (isset($tokens[$token])) {
                return ['token' => $token, 'pack' => $pack, 'label' => $tokens[$token]];
            }
        }

        return null;
    }

    /**
     * AS-7 §3.4 items 7, 12: the environment type label, always shown, and —
     * only while a site-binding mismatch is in effect — the Rebind button.
     * `manage_options` + nonce (checked in {@see rebindAction()}); no filter,
     * constant or ability can rebind (item 7). Restores nothing.
     */
    public function renderEnvironmentCard(): void
    {
        echo '<section class="aui-card"><div class="aui-card-h"><div><h2 class="aui-card-t">' . esc_html__('Environment', 'belayline-agent-governance') . '</h2><p class="aui-card-d">'
            . esc_html__('Production sites are stricter: watch-only (shadow) mode is capped at 24 hours, and turning it on means typing the pack name.', 'belayline-agent-governance') . '</p></div></div><div class="aui-card-b belayline-stack">';
        EnvironmentLabel::render($this->shadow);

        if ($this->environment !== null && $this->environment->isMismatched()) {
            echo Html::notice('error', __('This site\'s address changed.', 'belayline-agent-governance'), __("Watch-only windows, grants and approvals that were granted but not yet used have been voided for good. Confirm the new address to carry on.", 'belayline-agent-governance')); // phpcs:ignore WordPress.Security.EscapeOutput -- Html::notice() escapes.
            echo Html::postForm(self::REBIND, self::REBIND, Html::backTo(AdminUrls::SETTINGS)); // phpcs:ignore WordPress.Security.EscapeOutput -- built from esc_* helpers.
            echo '<button type="submit" class="aui-btn aui-btn--primary">' . esc_html__('Use this address', 'belayline-agent-governance') . '</button></form>';
        }
        echo '</div></section>';
    }

    public function rebindAction(): void
    {
        if (!current_user_can(self::CAP)) {
            wp_die(esc_html__('Insufficient permissions.', 'belayline-agent-governance'));
        }
        check_admin_referer(self::REBIND);

        $this->environment?->rebind();

        wp_safe_redirect(AdminUrls::tab(AdminUrls::SETTINGS));
        exit;
    }

    /**
     * The IP allowlist for application-password and WooCommerce API-key calls
     * (Settings tab). The saving administrator's own browser session is never
     * IP-checked, so a list that excludes their address is warned about, not blocked.
     */
    public function renderIpAllowlistCard(): void
    {
        $flash = isset($_GET['belayline_ip']) && is_scalar($_GET['belayline_ip']) ? sanitize_key((string) wp_unslash($_GET['belayline_ip'])) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only flash.
        if ($flash === 'saved') {
            echo Html::notice('success', __('Saved.', 'belayline-agent-governance'), __('The address list is in effect.', 'belayline-agent-governance')); // phpcs:ignore WordPress.Security.EscapeOutput -- Html::notice() escapes.
        } elseif ($flash === 'warn') {
            echo Html::notice('warning', __('Saved, but the list does not include the address you are using now.', 'belayline-agent-governance'), __('Your browser session is never checked against it, so you are not locked out. AI apps calling from that address would be refused.', 'belayline-agent-governance')); // phpcs:ignore WordPress.Security.EscapeOutput -- Html::notice() escapes.
        } elseif ($flash === 'invalid') {
            echo Html::notice('error', __('Nothing was saved.', 'belayline-agent-governance'), __('Every line must be an IPv4 or IPv6 address, or a range such as 203.0.113.0/24.', 'belayline-agent-governance')); // phpcs:ignore WordPress.Security.EscapeOutput -- Html::notice() escapes.
        }

        echo '<section class="aui-card"><div class="aui-card-h"><div><h2 class="aui-card-t">' . esc_html__('Allowed addresses', 'belayline-agent-governance') . '</h2><p class="aui-card-d">'
            . esc_html__('When you list addresses, calls from application passwords and WooCommerce API keys are refused unless they come from one of them. Logged-in browser sessions are never checked. Leave it empty to allow every address.', 'belayline-agent-governance') . '</p></div></div>';
        echo Html::postForm(self::IP, self::IP, Html::backTo(AdminUrls::SETTINGS)); // phpcs:ignore WordPress.Security.EscapeOutput -- built from esc_* helpers.
        echo '<div class="aui-card-b belayline-stack">';
        echo '<div class="aui-field"><label class="aui-label" for="belayline_ip_allowlist">' . esc_html__('Allowed addresses and ranges, one per line', 'belayline-agent-governance') . '</label>';
        echo '<textarea class="aui-textarea aui-mono" id="belayline_ip_allowlist" name="ip_allowlist" rows="5">' . esc_textarea(implode("\n", $this->ip->entries())) . '</textarea></div>';
        echo '<div class="aui-field"><label class="aui-label" for="belayline_trusted_proxies">' . esc_html__('Trusted proxies, one per line', 'belayline-agent-governance') . '</label>';
        echo '<textarea class="aui-textarea aui-mono" id="belayline_trusted_proxies" name="trusted_proxies" rows="3" aria-describedby="belayline_trusted_proxies_help">' . esc_textarea(implode("\n", $this->ip->trustedProxies())) . '</textarea>';
        echo '<p class="aui-help" id="belayline_trusted_proxies_help">' . esc_html__('Only requests that arrive through one of these addresses have their forwarded client address (X-Forwarded-For) trusted. Leave empty if you are not behind a proxy or CDN.', 'belayline-agent-governance') . '</p></div>';
        $current = $this->ip->clientIp();
        echo '<p class="aui-help">' . esc_html(sprintf(
            /* translators: %s: the administrator's current IP address */
            __('Your current address: %s', 'belayline-agent-governance'),
            $current ?? __('unknown', 'belayline-agent-governance')
        )) . '</p></div>';
        echo '<div class="aui-card-f"><button type="submit" class="aui-btn aui-btn--secondary">' . esc_html__('Save address list', 'belayline-agent-governance') . '</button></div></form></section>';
    }

    public function ipAllowlistAction(): void
    {
        if (!current_user_can(self::CAP)) {
            wp_die(esc_html__('Insufficient permissions.', 'belayline-agent-governance'));
        }
        check_admin_referer(self::IP);

        $result = $this->applyIpAllowlist(
            self::postedText($_POST['ip_allowlist'] ?? null), // phpcs:ignore WordPress.Security.ValidatedSanitizedInput,WordPress.Security.NonceVerification -- unslashed and sanitized inside postedText(); nonce checked above.
            self::postedText($_POST['trusted_proxies'] ?? null), // phpcs:ignore WordPress.Security.ValidatedSanitizedInput,WordPress.Security.NonceVerification -- unslashed and sanitized inside postedText(); nonce checked above.
        );

        $flash = !$result['saved'] ? 'invalid' : ($result['excludesCurrentIp'] ? 'warn' : 'saved');
        wp_safe_redirect(AdminUrls::tab(AdminUrls::SETTINGS, ['belayline_ip' => $flash]));
        exit;
    }

    /**
     * Save as the logged-in user; split from {@see ipAllowlistAction()} like
     * {@see applyPause()} so it is exercisable without a nonce, redirect or exit.
     *
     * @return array{saved: bool, invalid: list<string>, excludesCurrentIp: bool}
     */
    public function applyIpAllowlist(string $allowlistText, string $proxiesText): array
    {
        return $this->ip->save($allowlistText, $proxiesText, get_current_user_id());
    }

    /** A posted multi-line value, unslashed; a non-scalar (malformed request) is ''. */
    private static function postedText(mixed $raw): string
    {
        return is_scalar($raw) ? sanitize_textarea_field((string) wp_unslash($raw)) : '';
    }

    /** AS-7 §3.4 item 9: the production "Renew for 24h" re-confirmation — no typed name needed, unlike first enabling it. */
    public function renewShadowAction(): void
    {
        if (!current_user_can(self::CAP)) {
            wp_die(esc_html__('Insufficient permissions.', 'belayline-agent-governance'));
        }
        check_admin_referer(self::RENEW_SHADOW);

        $pack = isset($_POST['pack']) && is_scalar($_POST['pack'])
            ? sanitize_text_field((string) wp_unslash($_POST['pack']))
            : '';

        $this->applyRenewShadow($pack);

        wp_safe_redirect(AdminUrls::tab(AdminUrls::PACKS, ['belayline_saved' => '1']));
        exit;
    }

    /** Split from {@see renewShadowAction()} for the same reason as {@see applyShadow()}: exercisable without a nonce/redirect/exit. */
    public function applyRenewShadow(string $pack): void
    {
        if (!in_array($pack, $this->packs->registry()->names(), true)) {
            return;
        }

        $expiresAt = $this->shadow->renew($pack);
        if ($expiresAt === null) {
            return;
        }
        $this->changes->shadowEnabled($pack, $expiresAt);
    }

    /** The banner on every wp-admin screen while paused, for administrators, linking back here. */
    public function pausedNotice(): void
    {
        if (!current_user_can(self::CAP) || !$this->pause->isPaused()) {
            return;
        }

        echo '<div class="notice notice-error"><p><strong>'
            . esc_html__('Belayline Agent Governance: all agent actions are paused.', 'belayline-agent-governance')
            . '</strong> <a href="' . esc_url(AdminUrls::tab(AdminUrls::OVERVIEW)) . '">'
            . esc_html__('Review or resume', 'belayline-agent-governance')
            . '</a></p></div>';
    }

    public function pauseAction(): void
    {
        if (!current_user_can(self::CAP)) {
            wp_die(esc_html__('Insufficient permissions.', 'belayline-agent-governance'));
        }
        check_admin_referer(self::PAUSE);

        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitizePauseReason() unslashes and sanitizes the raw value itself; see its docblock.
        $this->applyPause(self::sanitizePauseReason($_POST['reason'] ?? null));

        wp_safe_redirect(self::backUrl());
        exit;
    }

    /**
     * The emergency-stop reason an admin typed: a slashed, unsanitised POST
     * value on the way in, a plain human-readable string on the way out
     * (stored raw in the `belayline_pause` option and shown escaped wherever
     * it's displayed). A non-scalar value (an array from a malformed
     * request) has no meaningful text representation, so it becomes ''
     * rather than a cast artifact like "Array".
     *
     * @param mixed $raw The raw `$_POST['reason']` value, or null if absent.
     */
    public static function sanitizePauseReason(mixed $raw): string
    {
        if (!is_scalar($raw)) {
            return '';
        }

        return sanitize_text_field((string) wp_unslash($raw));
    }

    public function resumeAction(): void
    {
        if (!current_user_can(self::CAP)) {
            wp_die(esc_html__('Insufficient permissions.', 'belayline-agent-governance'));
        }
        check_admin_referer(self::RESUME);

        $this->applyResume();

        wp_safe_redirect(self::backUrl());
        exit;
    }

    /** Back to the tab the stop button was pressed on (a whitelisted tab name; the nonce was checked first). */
    private static function backUrl(): string
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- only called after check_admin_referer(); the value is reduced to a known tab name.
        return AdminUrls::tab(AdminUrls::safeTab(isset($_POST['belayline_tab']) ? sanitize_text_field(wp_unslash((string) $_POST['belayline_tab'])) : null));
    }

    /**
     * Pause as the logged-in user. An empty reason is accepted: stopping the
     * agents must never be made harder by the form. Split from
     * {@see pauseAction()} for the same reason as {@see applyShadow()}.
     */
    public function applyPause(string $reason): void
    {
        $this->pause->pause(get_current_user_id(), $reason);
    }

    /** Resume as the logged-in user; see {@see PauseSwitch::resume()} for what it can and cannot lift. */
    public function applyResume(): void
    {
        $this->pause->resume(get_current_user_id());
    }

    /** @param list<string> $names */
    private function renderCatalog(array $names): void
    {
        $registry = $this->packs->registry();
        // The checkbox reflects the STORED option — what this form controls —
        // so saving an untouched form changes nothing. A pack shadowed only by
        // the filter is noted, not ticked, or the save would mint a stored
        // window the administrator never chose.
        $stored = $this->shadow->storedExpiries();
        $effective = $this->shadow->expiries();
        $isProduction = $this->shadow->isProduction();

        $renew = [];
        echo '<section class="aui-card" data-aui-filterbox aria-labelledby="belayline-packs-t"><div class="aui-card-h"><div><h2 class="aui-card-t" id="belayline-packs-t">'
            . esc_html__('Packs', 'belayline-agent-governance') . '</h2><p class="aui-card-d">'
            . esc_html__('Each pack lists what an agent bound to it can do on this site, and what it needs your approval for.', 'belayline-agent-governance') . '</p></div></div>';
        echo '<div class="aui-toolbar"><div class="aui-search">' . Icons::svg('magnifying-glass') . '<input class="aui-input" type="search" placeholder="' . esc_attr__('Search abilities', 'belayline-agent-governance') // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Icons::svg() escapes its own parts.
            . '" aria-label="' . esc_attr__('Search abilities', 'belayline-agent-governance') . '" data-aui-search></div>';
        echo '<div class="aui-seg" role="group" aria-label="' . esc_attr__('Show', 'belayline-agent-governance') . '" data-aui-filter>';
        foreach ([['all', __('All', 'belayline-agent-governance')], [Tiers::FREE, Tiers::label(Tiers::FREE)], [Tiers::APPROVAL, Tiers::label(Tiers::APPROVAL)], [Tiers::ALWAYS, Tiers::label(Tiers::ALWAYS)]] as [$value, $label]) {
            echo '<button type="button" aria-pressed="' . ($value === 'all' ? 'true' : 'false') . '" data-value="' . esc_attr($value) . '">' . esc_html($label) . '</button>';
        }
        echo '</div></div>';

        echo Html::postForm(self::SHADOW, self::SHADOW); // phpcs:ignore WordPress.Security.EscapeOutput -- built from esc_* helpers.
        echo '<div class="aui-groups">';
        foreach ($names as $name) {
            $pack = $registry->get($name);
            if (!$pack instanceof Pack) {
                continue;
            }
            $expiresAt = $stored[$pack->name] ?? null;
            $this->renderPackGroup($pack, false, $expiresAt, $effective[$pack->name] ?? null, $isProduction);
            if ($isProduction && $expiresAt !== null) {
                $renew[] = $pack->name;
            }
        }
        echo '</div>';

        echo '<div class="aui-card-f belayline-footer">';
        echo '<div class="aui-field"><label class="aui-label" for="belayline_shadow_days">' . esc_html__('Watch newly ticked packs for', 'belayline-agent-governance') . '</label><select class="aui-select" id="belayline_shadow_days" name="shadow_days">';
        foreach (self::SHADOW_DAYS as $days) {
            printf(
                '<option value="%d"%s>%s</option>',
                absint($days),
                selected($days, self::defaultShadowDays(), false),
                esc_html(sprintf(
                    /* translators: %d number of days */
                    _n('%d day', '%d days', $days, 'belayline-agent-governance'),
                    $days
                )),
            );
        }
        echo '</select></div><button type="submit" class="aui-btn aui-btn--secondary">' . esc_html__('Save watch-only settings', 'belayline-agent-governance') . '</button>';
        echo '</div></form>';
        // The "Renew" buttons submit these (HTML forms cannot nest inside the shadow form).
        foreach ($renew as $name) {
            echo Html::postForm(self::RENEW_SHADOW, self::RENEW_SHADOW, '<input type="hidden" name="pack" value="' . esc_attr($name) . '">', 'id="belayline-renew-' . esc_attr(sanitize_key($name)) . '"') . '</form>'; // phpcs:ignore WordPress.Security.EscapeOutput -- built from esc_* helpers.
        }
        echo '</section>';
    }

    /**
     * One pack as a collapsible group: its summary line, what its abilities do,
     * its standing rules in plain words, and the watch-only (shadow) switch.
     */
    private function renderPackGroup(Pack $pack, bool $open, ?int $storedExpiry, ?int $effectiveExpiry, bool $isProduction): void
    {
        $view = $this->overview?->forPack($pack);
        $id = 'belayline-pack-' . sanitize_key($pack->name);
        $counts = $view['counts'] ?? [];
        $total = $view === null ? 0 : count($view['rows']);

        echo '<div class="aui-group"><div class="aui-group-h"><button type="button" class="aui-group-toggle" aria-expanded="' . ($open ? 'true' : 'false') . '" aria-controls="' . esc_attr($id) . '">'
            . '<span class="aui-group-name">' . esc_html(PackLabel::of($pack->name)) . ' <code class="aui-code aui-muted">' . esc_html($pack->name) . '</code></span>';
        if ($storedExpiry !== null || $effectiveExpiry !== null) {
            echo ' ' . Html::badge('warn', __('Watch only', 'belayline-agent-governance'), 'clock'); // phpcs:ignore WordPress.Security.EscapeOutput -- Html::badge() escapes.
        }
        echo '<span class="aui-leader"></span>';
        if ($view !== null) {
            echo Tiers::metaSpan($total, $counts, false); // phpcs:ignore WordPress.Security.EscapeOutput -- metaSpan() escapes.
        }
        echo Icons::svg('caret-down') . '</button></div><div class="aui-group-b" id="' . esc_attr($id) . '"' . ($open ? '' : ' hidden') . '>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Icons::svg() escapes its own parts.

        if ($view !== null) {
            $shown = array_slice($view['rows'], 0, self::PACK_ROWS);
            foreach ($shown as $row) {
                echo '<div class="aui-item" data-kind="' . esc_attr($row['tier']) . '"><div class="aui-grow"><div class="aui-item-name">' . esc_html($row['label']);
                if ($row['label'] !== $row['name']) {
                    echo ' <code>' . esc_html($row['name']) . '</code>';
                }
                echo '</div></div>' . Tiers::badge($row['tier']) . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput -- Tiers::badge() escapes.
            }
            $extra = count($view['rows']) - count($shown);
            if ($extra > 0 || $view['refused'] > 0 || $shown === []) {
                $notes = [];
                if ($extra > 0) {
                    /* translators: %d: number of abilities not listed */
                    $notes[] = sprintf(_n('%d more ability not shown', '%d more abilities not shown', $extra, 'belayline-agent-governance'), $extra);
                }
                if ($view['refused'] > 0) {
                    /* translators: %d: number of abilities this pack refuses */
                    $notes[] = sprintf(_n('%d registered ability is refused by this pack', '%d registered abilities are refused by this pack', $view['refused'], 'belayline-agent-governance'), $view['refused']);
                }
                if ($shown === []) {
                    $notes[] = __('This pack lets no registered ability run.', 'belayline-agent-governance');
                }
                echo '<div class="aui-item"><span class="aui-muted">' . esc_html(implode('. ', $notes)) . '.</span></div>';
            }
        }

        echo '<div class="aui-card-b belayline-stack">' . $this->packRules($pack); // phpcs:ignore WordPress.Security.EscapeOutput -- packRules() escapes.
        printf(
            '<label class="belayline-check"><input type="checkbox" name="shadow[]" value="%s"%s> <span><strong>%s</strong> %s%s</span></label>',
            esc_attr($pack->name),
            checked($storedExpiry !== null, true, false),
            esc_html__('Watch only (shadow mode).', 'belayline-agent-governance'),
            esc_html__('Record what would happen and block nothing.', 'belayline-agent-governance'),
            $this->expiryNote($storedExpiry, $effectiveExpiry), // phpcs:ignore WordPress.Security.EscapeOutput -- esc_html in helper.
        );
        if ($isProduction && $storedExpiry === null) {
            printf(
                '<div class="aui-field"><label class="aui-label" for="belayline-confirm-%1$s">%2$s</label><input class="aui-input belayline-narrow" type="text" id="belayline-confirm-%1$s" name="shadow_confirm[%3$s]" placeholder="%3$s" autocomplete="off"></div>',
                esc_attr(sanitize_key($pack->name)),
                esc_html__('To turn it on in production, type the pack name', 'belayline-agent-governance'),
                esc_attr($pack->name),
            );
        }
        if ($isProduction && $storedExpiry !== null) {
            echo $this->renewShadowButton($pack->name); // phpcs:ignore WordPress.Security.EscapeOutput -- esc_* in helper.
        }
        echo '</div></div></div>';
    }

    /** A pack's standing rules in plain words. */
    private function packRules(Pack $pack): string
    {
        $blocked = [];
        foreach ($pack->denyClass as $class) {
            $blocked[] = match ($class) {
                'tier1' => __('changes to your site', 'belayline-agent-governance'),
                'tier2' => __('actions that cannot be undone', 'belayline-agent-governance'),
                default => $class,
            };
        }
        $out = '<dl class="aui-kv">';
        $out .= '<dt>' . esc_html__('Never allowed', 'belayline-agent-governance') . '</dt><dd>' . esc_html($blocked === [] ? '—' : implode(', ', $blocked)) . '</dd>';
        $out .= '<dt>' . esc_html__('Customer details', 'belayline-agent-governance') . '</dt><dd>' . esc_html($pack->redactsPii() ? __('Hidden from the agent and the log', 'belayline-agent-governance') : __('Shown in full', 'belayline-agent-governance')) . '</dd>';
        if ($pack->hasRateLimits()) {
            $parts = [];
            if (($pack->limits['calls_per_minute'] ?? null) !== null) {
                /* translators: %d: calls per minute */
                $parts[] = sprintf(__('%d per minute', 'belayline-agent-governance'), (int) $pack->limits['calls_per_minute']);
            }
            if (($pack->limits['calls_per_hour'] ?? null) !== null) {
                /* translators: %d: calls per hour */
                $parts[] = sprintf(__('%d per hour', 'belayline-agent-governance'), (int) $pack->limits['calls_per_hour']);
            }
            $out .= '<dt>' . esc_html__('Call limit', 'belayline-agent-governance') . '</dt><dd>' . esc_html(implode(', ', $parts)) . '</dd>';
        }
        if ($pack->hasArgumentCaps()) {
            $out .= '<dt>' . esc_html__('Spending and size caps', 'belayline-agent-governance') . '</dt><dd>' . esc_html(sprintf(
                /* translators: %d: number of caps */
                _n('%d cap', '%d caps', count($pack->argumentCaps), 'belayline-agent-governance'),
                count($pack->argumentCaps)
            )) . '</dd>';
        }

        return $out . '</dl>';
    }

    public function saveShadow(): void
    {
        if (!current_user_can(self::CAP)) {
            wp_die(esc_html__('Insufficient permissions.', 'belayline-agent-governance'));
        }
        check_admin_referer(self::SHADOW);

        // phpcs:disable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- applyShadow() below sanitizes and allow-list-validates every element (pack names, confirmation text, days) before use.
        $posted = isset($_POST['shadow']) && is_array($_POST['shadow']) ? wp_unslash($_POST['shadow']) : [];
        $days = isset($_POST['shadow_days']) && is_scalar($_POST['shadow_days'])
            ? (int) wp_unslash($_POST['shadow_days'])
            : self::defaultShadowDays();
        $confirmations = isset($_POST['shadow_confirm']) && is_array($_POST['shadow_confirm'])
            ? wp_unslash($_POST['shadow_confirm'])
            : [];
        // phpcs:enable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

        $this->applyShadow($posted, $days, $confirmations);

        wp_safe_redirect(AdminUrls::tab(AdminUrls::PACKS, ['belayline_saved' => '1']));
        exit;
    }

    /**
     * Sanitise and persist the shadow form, auditing each pack whose state
     * changed — lapsed entries the save swept up as `shadow.expired`, unticked
     * packs as `shadow.disabled`, newly ticked packs as `shadow.enabled` with
     * their expiry. Split from {@see saveShadow()} so the persistence and its
     * audit rows can be exercised without a nonce, a redirect or an exit.
     *
     * A duration outside {@see SHADOW_DAYS} falls to the SHORTEST offered,
     * not the default: shadow mode loosens enforcement, so a malformed choice
     * buys the least of it.
     *
     * AS-7 §3.4 item 9: on a production site, a pack NOT already validly
     * shadowed is only enabled when $confirmations[pack] is typed back
     * exactly — an already-shadowed pack being re-saved needs no retyping
     * (that is what the separate "Renew for 24h" button is for).
     *
     * @param array<mixed> $posted        The raw `shadow[]` values.
     * @param array<mixed> $confirmations The raw `shadow_confirm[pack]` values.
     */
    public function applyShadow(array $posted, int $days, array $confirmations = []): void
    {
        $valid = $this->packs->registry()->names();
        $isProduction = $this->shadow->isProduction();
        $alreadyShadowed = $this->shadow->storedExpiries();

        $clean = [];
        foreach ($posted as $pack) {
            $pack = sanitize_text_field((string) $pack);
            if ($pack === '' || !in_array($pack, $valid, true) || in_array($pack, $clean, true)) {
                continue;
            }
            if ($isProduction && !isset($alreadyShadowed[$pack])) {
                $typed = isset($confirmations[$pack]) && is_scalar($confirmations[$pack])
                    ? sanitize_text_field((string) $confirmations[$pack])
                    : '';
                if ($typed !== $pack) {
                    continue;
                }
            }
            $clean[] = $pack;
        }
        if (!in_array($days, self::SHADOW_DAYS, true)) {
            $days = min(self::SHADOW_DAYS);
        }

        $changed = $this->shadow->apply($clean, $days * 24 * 60 * 60);

        foreach ($changed['expired'] as $pack => $expiresAt) {
            $this->changes->shadowExpired($pack, $expiresAt);
        }
        foreach ($changed['disabled'] as $pack) {
            $this->changes->shadowDisabled($pack);
        }
        foreach ($changed['enabled'] as $pack => $expiresAt) {
            $this->changes->shadowEnabled($pack, $expiresAt);
        }
    }

    private static function defaultShadowDays(): int
    {
        return max(self::SHADOW_DAYS);
    }

    /** The line under a pack's shadow checkbox: its stored expiry, else a filter-only shadow, else nothing. */
    private function expiryNote(?int $stored, ?int $effective): string
    {
        if ($stored !== null) {
            $note = sprintf(
                /* translators: %s expiry date and time (UTC) */
                __('expires %s', 'belayline-agent-governance'),
                gmdate('Y-m-d H:i', $stored) . ' UTC'
            );
        } elseif ($effective !== null) {
            $note = sprintf(
                /* translators: 1: filter hook name, 2: expiry date and time (UTC) */
                __('shadowed by the %1$s filter until %2$s', 'belayline-agent-governance'),
                ShadowMode::FILTER,
                gmdate('Y-m-d H:i', $effective) . ' UTC'
            );
        } else {
            return '';
        }

        return '<br><span class="aui-muted">' . esc_html($note) . '</span>';
    }

    /** AS-7 §3.4 item 9: the production "Renew for 24h" re-confirmation button for an already-shadowed pack. */
    private function renewShadowButton(string $pack): string
    {
        // A separate form cannot nest inside the shadow form, so the button
        // posts to the same handler through the `form` attribute.
        return '<button type="submit" class="aui-btn aui-btn--ghost aui-btn--sm" form="belayline-renew-' . esc_attr(sanitize_key($pack)) . '">'
            . esc_html__('Renew for 24 hours', 'belayline-agent-governance') . '</button>';
    }

    /**
     * The bindings card: one table per configured {@see IdentityProvider}.
     * Renders a helpful empty-state line instead of an empty form when NO
     * provider has any bindable token (e.g. WooCommerce inactive and no users
     * beyond the admin).
     *
     * @param list<string>          $names
     * @param array<string, string> $bindings
     * @param array{token: string, pack: string, label: string}|null $prefill
     */
    private function renderBindings(array $names, string $default, array $bindings, ?array $prefill = null): void
    {
        echo '<section class="aui-card" aria-labelledby="belayline-bindings-t"><div class="aui-card-h"><div><h2 class="aui-card-t" id="belayline-bindings-t">' . esc_html__('Who uses which pack', 'belayline-agent-governance') . '</h2><p class="aui-card-d">'
            . esc_html(sprintf(
                /* translators: %s: default pack name */
                __('Choose a pack for each role, person, application password or API key. Anything left on the default uses the %s pack.', 'belayline-agent-governance'),
                PackLabel::of($default)
            )) . '</p></div></div>';

        $providers = array_values(array_filter(
            $this->identity->providers(),
            static fn (IdentityProvider $provider): bool => $provider->bindableTokens() !== [],
        ));

        if ($providers === []) {
            echo '<div class="aui-empty"><span class="aui-tile">' . Icons::svg('key', 'aui-i--lg') . '</span><h3 class="aui-card-t">' . esc_html__('Nothing to bind yet', 'belayline-agent-governance') . '</h3><p>' // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Icons::svg() escapes its own parts.
                . esc_html__('Roles, people, application passwords and API keys show up here as soon as there are any.', 'belayline-agent-governance') . '</p></div></section>';

            return;
        }

        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" data-aui-form>';
        echo '<input type="hidden" name="action" value="' . esc_attr(self::SAVE) . '">';
        echo wp_nonce_field(self::SAVE, '_wpnonce', true, false); // phpcs:ignore WordPress.Security.EscapeOutput -- wp_nonce_field returns safe markup.

        if ($prefill !== null) {
            echo '<div class="aui-card-b">' . Html::notice('info', __('Site Agent asked to use a pack.', 'belayline-agent-governance'), sprintf( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts (Html helpers and esc_* calls).
                /* translators: 1: role or user display name, 2: capability pack name */
                __('It would like %1$s to use the %2$s pack. Check the highlighted row and save.', 'belayline-agent-governance'), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts (Html helpers and esc_* calls).
                $prefill['label'], // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts (Html helpers and esc_* calls).
                $prefill['pack'] // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts (Html helpers and esc_* calls).
            )) . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput -- Html::notice() escapes.
        }

        foreach ($providers as $provider) {
            $this->renderProviderSection($provider, $names, $default, $bindings, $prefill);
        }

        echo '<div class="aui-savebar"><span class="aui-note">' . esc_html__('Changes apply on the agent\'s next call.', 'belayline-agent-governance') . '</span>'
            . '<button type="button" class="aui-btn aui-btn--secondary" data-aui-discard>' . esc_html__('Discard', 'belayline-agent-governance') . '</button>'
            . '<button type="submit" class="aui-btn aui-btn--primary">' . esc_html__('Save changes', 'belayline-agent-governance') . '</button></div>';
        echo '</form></section>';

        if ($prefill !== null) {
            echo '<script>document.getElementById("' . self::PREFILL_ROW . '")?.scrollIntoView({block:"center"});</script>'; // phpcs:ignore WordPress.Security.EscapeOutput -- static constant.
        }
    }

    /**
     * @param list<string>          $names
     * @param array<string, string> $bindings
     * @param array{token: string, pack: string, label: string}|null $prefill
     */
    private function renderProviderSection(IdentityProvider $provider, array $names, string $default, array $bindings, ?array $prefill = null): void
    {
        $tokens = $provider->bindableTokens();
        $heading = 'belayline-prov-' . substr(md5($provider->label()), 0, 8);

        echo '<div class="aui-card-b belayline-provider"><h3 class="aui-label-caps" id="' . esc_attr($heading) . '">' . esc_html($provider->label()) . '</h3></div>';
        echo '<div class="aui-table-wrap" tabindex="0" role="region" aria-labelledby="' . esc_attr($heading) . '"><table class="aui-table" aria-labelledby="' . esc_attr($heading) . '"><thead><tr>';
        foreach ([__('Credential', 'belayline-agent-governance'), __('Pack', 'belayline-agent-governance')] as $col) {
            echo '<th scope="col">' . esc_html($col) . '</th>';
        }
        echo '</tr></thead><tbody>';

        foreach ($tokens as $token => $description) {
            $token = (string) $token;
            $current = $bindings[$token] ?? '';
            $asked = $prefill !== null && $prefill['token'] === $token;
            if ($asked) {
                $current = $prefill['pack'];
            }
            echo $asked
                ? '<tr id="' . esc_attr(self::PREFILL_ROW) . '" class="is-highlight">'
                : '<tr>'; // phpcs:ignore WordPress.Security.EscapeOutput -- static markup plus esc_attr'd constant.
            $selectId = 'belayline-bind-' . substr(md5($token), 0, 10);
            echo '<td><label for="' . esc_attr($selectId) . '">' . esc_html($description !== '' ? $description : $token) . '</label> <code class="aui-code aui-muted">' . esc_html($token) . '</code></td>';
            echo '<td>' . $this->packSelect($selectId, $token, $names, $default, $current) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput -- esc_* in helper.
            echo '</tr>';
        }

        echo '</tbody></table></div>';
    }

    /**
     * @param list<string> $names
     */
    private function packSelect(string $id, string $subject, array $names, string $default, string $current): string
    {
        $out = '<select class="aui-select" id="' . esc_attr($id) . '" name="bindings[' . esc_attr($subject) . ']">';
        $out .= '<option value="">' . esc_html(sprintf(/* translators: %s default pack name */ __('Default (%s)', 'belayline-agent-governance'), PackLabel::of($default))) . '</option>';
        foreach ($names as $name) {
            $out .= '<option value="' . esc_attr($name) . '"' . selected($current, $name, false) . '>' . esc_html(PackLabel::of($name)) . '</option>';
        }
        $out .= '</select>';

        return $out;
    }

    public function save(): void
    {
        if (!current_user_can(self::CAP)) {
            wp_die(esc_html__('Insufficient permissions.', 'belayline-agent-governance'));
        }
        check_admin_referer(self::SAVE);

        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- applyBindings() below sanitizes and allow-list-validates every subject/pack pair before use.
        $posted = isset($_POST['bindings']) && is_array($_POST['bindings']) ? wp_unslash($_POST['bindings']) : [];

        $this->applyBindings($posted);

        wp_safe_redirect(AdminUrls::tab(AdminUrls::PACKS, ['belayline_saved' => '1']));
        exit;
    }

    /**
     * Sanitise and persist the bindings form, auditing one `binding.changed`
     * row per subject whose pack differs from what the option held — a
     * binding that is dropped back to the default counts as a change, a
     * binding re-saved unchanged does not. Split from {@see save()} for the
     * same reason as {@see applyShadow()}.
     *
     * @param array<mixed> $posted The raw `bindings[subject]` values.
     */
    public function applyBindings(array $posted): void
    {
        $valid = $this->packs->registry()->names();
        $before = $this->packs->storedBindings();

        $clean = [];
        foreach ($posted as $subject => $pack) {
            $subject = sanitize_text_field((string) $subject);
            $pack = sanitize_text_field((string) $pack);
            // Empty = "use default" (no binding stored); only persist valid named packs.
            if ($subject !== '' && $pack !== '' && in_array($pack, $valid, true)) {
                $clean[$subject] = $pack;
            }
        }

        update_option(PackResolver::BINDINGS_OPTION, $clean, false);
        $this->packs->flush();

        foreach (array_unique(array_merge(array_keys($before), array_keys($clean))) as $subject) {
            $from = $before[$subject] ?? null;
            $to = $clean[$subject] ?? null;
            if ($from !== $to) {
                $this->changes->bindingChanged((string) $subject, $from, $to);
            }
        }
    }
}
