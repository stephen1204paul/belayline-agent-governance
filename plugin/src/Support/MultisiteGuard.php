<?php

declare(strict_types=1);

namespace Belayline\Plugin\Support;

/**
 * Multisite refusal (S2). Belayline does not support WordPress multisite:
 * a network install spreads the identity/gate/audit wiring and the approvals
 * schema across sites in ways this plugin has never been designed or tested
 * for, so it refuses outright rather than risk a half-governed network.
 * Mirrors Site Agent's own `MultisiteGuard` for consistency between the two
 * plugins.
 */
final class MultisiteGuard
{
    /** Whether this install must be refused. */
    public static function refused(): bool
    {
        return function_exists('is_multisite') && is_multisite();
    }

    /** The one sentence shown to the person who tried. */
    public static function message(): string
    {
        return __('Belayline Agent Governance does not support WordPress multisite. It was not activated.', 'belayline-agent-governance');
    }

    /**
     * Activation hook body: deactivates the plugin (defensive — WordPress has
     * not yet recorded it as active at this point) and dies before
     * activate_plugin() can report success, for both per-site and network
     * activation.
     */
    public static function refuseActivation(string $pluginFile): void
    {
        if (!self::refused()) {
            return;
        }

        deactivate_plugins(plugin_basename($pluginFile));

        wp_die(
            esc_html(self::message()),
            esc_html__('Plugin activation refused', 'belayline-agent-governance'),
            ['back_link' => true]
        );
    }

    /**
     * Runtime guard for a copy that is somehow active on a multisite install
     * (e.g. a site converted to multisite after activation): deactivates it
     * and shows the admin notice, so no other part of the plugin wires up.
     */
    public static function refuseRuntime(string $pluginFile): void
    {
        if (!self::refused()) {
            return;
        }

        deactivate_plugins(plugin_basename($pluginFile));
        add_action('admin_notices', [self::class, 'renderNotice']);
    }

    /** Runtime notice for an already-active copy on a multisite install. */
    public static function renderNotice(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        printf(
            '<div class="notice notice-error"><p>%s</p></div>',
            esc_html__('Belayline Agent Governance is inactive: WordPress multisite is not supported. Deactivate it on this network.', 'belayline-agent-governance')
        );
    }
}
