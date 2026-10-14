<?php

declare(strict_types=1);

namespace Belayline\Plugin\Integrations\Woo;

use Belayline\Policy\ElevationRule;
use Belayline\Policy\Tier;

/**
 * An order write that shapes the money (items, fees, shipping, coupons) or
 * reassigns the customer changes what is charged and who is emailed, in every
 * pack. All five are accepted by the REST orders schema
 * (class-wc-rest-orders-v2-controller.php:744-747, 811-819).
 */
final class OrderMoneyShapingElevationRule implements ElevationRule
{
    private const VERBS = ['woocommerce/orders-create', 'woocommerce/orders-update'];

    private const MONEY_ARGS = ['line_items', 'fee_lines', 'shipping_lines', 'coupon_lines', 'customer_id'];

    /** @param array<string, mixed> $args */
    public function apply(string $verb, array $args, Tier $currentTier): ?Tier
    {
        if (!in_array($verb, self::VERBS, true)) {
            return null;
        }

        foreach (self::MONEY_ARGS as $key) {
            if (array_key_exists($key, $args) && $args[$key] !== null) {
                return Tier::Irreversible;
            }
        }

        return null;
    }
}
