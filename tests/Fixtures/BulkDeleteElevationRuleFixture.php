<?php

declare(strict_types=1);

namespace Belayline\Tests\Fixtures;

use Belayline\Policy\ElevationRule;
use Belayline\Policy\Tier;

/**
 * Test-only mirror of the moved `Belayline\Plugin\Integrations\Woo\BulkProductDeleteElevationRule`:
 * a bulk delete elevates beyond single-item (single = recoverable from snapshot).
 */
final class BulkDeleteElevationRuleFixture implements ElevationRule
{
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
