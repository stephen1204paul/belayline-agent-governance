<?php

declare(strict_types=1);

namespace Belayline\Plugin\Integrations\Woo;

use Belayline\Policy\ElevationRule;
use Belayline\Policy\Tier;

/**
 * Bulk product delete elevates beyond single-item (single = recoverable from
 * snapshot; bulk is not).
 */
final class BulkProductDeleteElevationRule implements ElevationRule
{
    /** @param array<string, mixed> $args */
    public function apply(string $verb, array $args, Tier $currentTier): ?Tier
    {
        if ($verb !== 'woocommerce/products-delete' || !self::isBulk($args)) {
            return null;
        }

        return Tier::Irreversible;
    }

    /** @param array<string, mixed> $args */
    private static function isBulk(array $args): bool
    {
        if (!empty($args['bulk'])) {
            return true;
        }
        $ids = $args['ids'] ?? null;

        return is_array($ids) && count($ids) > 1;
    }
}
