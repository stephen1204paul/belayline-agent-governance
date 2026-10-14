<?php

declare(strict_types=1);

namespace Belayline\Plugin\Integrations\Woo;

use Belayline\Policy\ElevationRule;
use Belayline\Policy\Tier;

/**
 * An order status update flipping to a status with customer-facing side
 * effects is irreversible regardless of the verb's base tier: fulfillment
 * statuses fire fulfillment + customer emails, `cancelled` restocks and
 * emails the customer, `refunded` marks the money as returned, `on-hold` and `pending` mail the customer
 * an on-hold / payment-due notice. `set_paid: true` is equivalent to moving to processing.
 */
final class OrderFulfillmentElevationRule implements ElevationRule
{
    /** Order statuses whose transition cannot be quietly undone => irreversible. */
    private const IRREVERSIBLE_STATUSES = ['processing', 'completed', 'shipped', 'cancelled', 'refunded', 'on-hold', 'pending'];

    /**
     * The MCP-bridge verbs (create/update) and the session-visible verb all carry a `status` arg;
     * the bridge forwards input straight into the REST request (RestAbilityFactory.php:614-616).
     */
    private const VERBS = ['woocommerce/orders-create', 'woocommerce/orders-update', 'woocommerce/order-update-status'];

    /** @param array<string, mixed> $args */
    public function apply(string $verb, array $args, Tier $currentTier): ?Tier
    {
        if (!in_array($verb, self::VERBS, true)) {
            return null;
        }

        // set_paid:true runs payment_complete() (class-wc-rest-orders-v2-controller.php:843-846):
        // processing status, stock reduction, customer email. false/absent keeps the status-only behaviour.
        if (array_key_exists('set_paid', $args) && filter_var($args['set_paid'], FILTER_VALIDATE_BOOLEAN)) {
            return Tier::Irreversible;
        }

        $status = OrderStatus::normalize($args['status'] ?? null);

        return $status !== null && in_array($status, self::IRREVERSIBLE_STATUSES, true)
            ? Tier::Irreversible
            : null;
    }
}
