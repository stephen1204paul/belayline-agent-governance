<?php

declare(strict_types=1);

namespace Belayline\Plugin\Integrations\SureCart;

use Belayline\Policy\ElevationRule;
use Belayline\Policy\Tier;

/** A fulfillment with notifications_enabled emails the customer: an un-sendable side effect. */
final class FulfillmentNotificationElevationRule implements ElevationRule
{
    private const VERBS = ['surecart/create-fulfillment', 'surecart/update-fulfillment'];

    /** @param array<string, mixed> $args */
    public function apply(string $verb, array $args, Tier $currentTier): ?Tier
    {
        if (!in_array($verb, self::VERBS, true)) {
            return null;
        }

        return SureCartTruthy::flagOn($args['notifications_enabled'] ?? null) ? Tier::Irreversible : null;
    }
}
