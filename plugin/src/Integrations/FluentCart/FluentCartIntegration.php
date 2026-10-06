<?php

declare(strict_types=1);

namespace Specflux\AgentSafety\Plugin\Integrations\FluentCart;

use Specflux\AgentSafety\Plugin\Approval\StateProbe;
use Specflux\AgentSafety\Policy\ElevationRule;
use Specflux\AgentSafety\Policy\VerbCatalog;
use wpdb;

/**
 * The one place all FluentCart coupling lives (governance only: no capture or
 * restore adapters yet, SPEC-COMMERCE-INTEGRATIONS.md section 6 C2b). Wired from
 * the plugin bootstrap ONLY when {@see available()}. Contributes no packs and no
 * identity provider: FluentCart abilities are reached by the existing
 * application-password / user-role identities, and bind through admin-created packs.
 *
 * Note FluentCart's MCP module is OFF by default (option `mcp` in
 * `fluent_cart_modules_settings`): with it off no `fluent-cart/` ability exists,
 * and this module simply has nothing to judge.
 */
final class FluentCartIntegration
{
    /**
     * Lowest FluentCart version this module's catalog is verified against.
     * 1.7.0 is the release whose `app/Modules/MCP/` source was read to build
     * {@see FluentCartVerbCatalog}; earlier versions may register a different
     * ability set, and an ungoverned `fluent-cart/` verb would be refused
     * (`unknown_verb`) rather than classified, so we do not claim them.
     */
    public const MIN_VERSION = '1.7.0';

    /** Verbs that move money through a gateway or cancel one; never eligible for a standing Grant. */
    public const UNGRANTABLE_VERBS = [
        'fluent-cart/refund-order',
        'fluent-cart/change-subscription-status',
    ];

    public static function available(): bool
    {
        return defined('FLUENTCART_VERSION') && self::isSupportedVersion(constant('FLUENTCART_VERSION'));
    }

    public static function isSupportedVersion(mixed $version): bool
    {
        return is_string($version) && $version !== '' && version_compare($version, self::MIN_VERSION, '>=');
    }

    /**
     * Filter callback for `agent_safety_grant_eligible` (GrantGate; default
     * false): forces ineligible for {@see UNGRANTABLE_VERBS} whatever any other
     * callback said, so each refund or cancel needs its own human approval.
     * Register at PHP_INT_MAX priority. It can only narrow.
     *
     * @param mixed $eligible
     * @param mixed $grant
     * @param array<string, mixed> $args
     */
    public static function grantEligible($eligible, $grant = null, string $verb = '', array $args = []): bool
    {
        if (in_array($verb, self::UNGRANTABLE_VERBS, true)) {
            return false;
        }

        return $eligible === true;
    }

    /**
     * @return array{elevationRules: list<ElevationRule>, packs: list<never>, governedNamespaces: list<string>, stateProbes: array<string, StateProbe>}
     */
    public static function register(VerbCatalog $catalog, ?wpdb $db): array
    {
        $catalog->register(FluentCartVerbCatalog::MAP);

        $couponStatus = null;
        $probes = [];
        if ($db !== null) {
            $couponStatus = static function (int $id) use ($db): ?string {
                $status = $db->get_var($db->prepare('SELECT `status` FROM %i WHERE `id` = %d LIMIT 1', $db->prefix . 'fct_coupons', $id));

                return is_string($status) ? $status : null;
            };
            $order = new FluentCartOrderStateProbe($db);
            $probes = [
                'fluent-cart/change-order-status' => $order,
                'fluent-cart/refund-order' => $order,
                'fluent-cart/change-subscription-status' => new FluentCartSubscriptionStateProbe($db),
                'fluent-cart/manage-coupon' => new FluentCartCouponStateProbe($db),
                'fluent-cart/upsert-customer' => new FluentCartCustomerStateProbe($db),
            ];
        }

        return [
            'elevationRules' => [
                new CustomerVisibleNoteElevationRule(),
                new CouponActivationElevationRule($couponStatus),
                new CustomerEmailChangeElevationRule(),
            ],
            'packs' => [],
            'governedNamespaces' => ['fluent-cart/'],
            // add-order-note and apply-labels append/toggle with no meaningful
            // prior state to go stale, and every read/query verb is unprobed.
            'stateProbes' => $probes,
        ];
    }
}
