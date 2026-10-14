<?php

declare(strict_types=1);

namespace Belayline\Plugin\Integrations\SureCart;

use Belayline\Policy\ElevationRule;
use Belayline\Policy\Tier;

/**
 * update-price that sets an amount, or archives the price, goes live for
 * customers immediately and cannot be undone for anyone who already paid.
 * Renaming a price (name only) stays Tier 1. Un-archiving (archived=false)
 * stays Tier 1.
 */
final class PriceElevationRule implements ElevationRule
{
    /** @param array<string, mixed> $args */
    public function apply(string $verb, array $args, Tier $currentTier): ?Tier
    {
        if ($verb !== 'surecart/update-price') {
            return null;
        }

        if (SureCartTruthy::isSet($args, 'amount') || SureCartTruthy::flagOn($args['archived'] ?? null)) {
            return Tier::Irreversible;
        }

        return null;
    }
}
