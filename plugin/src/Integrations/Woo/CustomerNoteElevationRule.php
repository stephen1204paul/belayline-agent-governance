<?php

declare(strict_types=1);

namespace Specflux\AgentSafety\Plugin\Integrations\Woo;

use Specflux\AgentSafety\Policy\ElevationRule;
use Specflux\AgentSafety\Policy\Tier;

/**
 * An order note added as a CUSTOMER note is emailed to the customer
 * immediately — irreversible regardless of the verb's base tier, unlike a
 * private (internal) note which never leaves wp-admin.
 */
final class CustomerNoteElevationRule implements ElevationRule
{
    /** @param array<string, mixed> $args */
    public function apply(string $verb, array $args, Tier $currentTier): ?Tier
    {
        if ($verb !== 'woocommerce/order-add-note') {
            return null;
        }

        // The ability's arg is `customer_note` (OrderAddNote.php:93-94, cast to bool);
        // `is_customer_note` is the WC_Order::add_order_note() param name, refused too in case a
        // bridge forwards it.
        foreach (['customer_note', 'is_customer_note'] as $key) {
            if (!empty($args[$key])) {
                return Tier::Irreversible;
            }
        }

        return null;
    }
}
