<?php

declare(strict_types=1);

namespace Belayline\Plugin\Integrations\Woo;

use Belayline\Policy\ElevationRule;
use Belayline\Policy\Tier;

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

        return !empty($args['customer_note']) ? Tier::Irreversible : null;
    }
}
