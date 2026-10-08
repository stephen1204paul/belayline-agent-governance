<?php

declare(strict_types=1);

namespace Specflux\AgentSafety\Plugin\Integrations\SureCart;

use Specflux\AgentSafety\Policy\ElevationRule;
use Specflux\AgentSafety\Policy\Tier;

/**
 * A coupon discounting 50% or more, or a fixed amount above a threshold,
 * elevates to Tier 2. amount_off is in the smallest unit of the coupon's own
 * currency and is compared as-is, with no currency conversion: the threshold
 * is a flat figure in minor units regardless of currency.
 */
final class DiscountElevationRule implements ElevationRule
{
    public const PERCENT_THRESHOLD = 50.0;

    /** Minor units (10000 = 100.00 in the coupon's currency). Filterable. */
    public const DEFAULT_AMOUNT_THRESHOLD = 10000;

    public const AMOUNT_THRESHOLD_FILTER = 'agent_safety_surecart_discount_amount_threshold';

    private const VERBS = ['surecart/create-coupon', 'surecart/update-coupon'];

    /** @param array<string, mixed> $args */
    public function apply(string $verb, array $args, Tier $currentTier): ?Tier
    {
        if (!in_array($verb, self::VERBS, true)) {
            return null;
        }

        if (SureCartTruthy::isSet($args, 'percent_off') && self::percentTooHigh($args['percent_off'])) {
            return Tier::Irreversible;
        }

        if (SureCartTruthy::isSet($args, 'amount_off') && self::amountTooHigh($args['amount_off'])) {
            return Tier::Irreversible;
        }

        return null;
    }

    public static function amountThreshold(): int
    {
        $value = function_exists('apply_filters')
            ? apply_filters('agent_safety_surecart_discount_amount_threshold', self::DEFAULT_AMOUNT_THRESHOLD)
            : self::DEFAULT_AMOUNT_THRESHOLD;

        // A broken filter must not disable the rule: fall back to the default.
        return is_int($value) && $value >= 0 ? $value : self::DEFAULT_AMOUNT_THRESHOLD;
    }

    private static function percentTooHigh(mixed $value): bool
    {
        // Same coercion SureCart uses (floatval); non-scalars fail closed.
        return !is_scalar($value) || floatval($value) >= self::PERCENT_THRESHOLD;
    }

    private static function amountTooHigh(mixed $value): bool
    {
        // SureCart uses absint(); non-scalars fail closed.
        return !is_scalar($value) || abs((int) $value) > self::amountThreshold();
    }
}
