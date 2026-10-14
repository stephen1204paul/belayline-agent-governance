<?php

declare(strict_types=1);

namespace Belayline\Plugin\Integrations\FluentCart;

use Belayline\Policy\ElevationRule;
use Belayline\Policy\Tier;

/**
 * `upsert-customer` renames an existing customer's email only through
 * `new_email` (CustomerTools.php:190-196; `email` merely identifies the
 * record). An email change moves the identity link to a WordPress user and
 * every future customer email, so it is Tier 2 (spec 3.2). Any non-empty
 * `new_email` elevates, without checking the customer exists: fail closed.
 */
final class CustomerEmailChangeElevationRule implements ElevationRule
{
    /** @param array<string, mixed> $args */
    public function apply(string $verb, array $args, Tier $currentTier): ?Tier
    {
        if ($verb !== 'fluent-cart/upsert-customer') {
            return null;
        }

        $new = $args['new_email'] ?? null;
        $present = is_string($new) ? trim($new) !== '' : !empty($new);

        return $present ? Tier::Irreversible : null;
    }
}
