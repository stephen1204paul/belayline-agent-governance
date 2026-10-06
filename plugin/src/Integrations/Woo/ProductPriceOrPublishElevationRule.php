<?php

declare(strict_types=1);

namespace Specflux\AgentSafety\Plugin\Integrations\Woo;

use Specflux\AgentSafety\Policy\ElevationRule;
use Specflux\AgentSafety\Policy\Tier;

/**
 * A product create/update that sets a price or publishes is irreversible
 * regardless of the verb's base tier: a live price change or a publish is
 * customer-facing the moment it lands, and there is no snapshot that
 * un-charges a customer who already bought at the wrong price.
 */
final class ProductPriceOrPublishElevationRule implements ElevationRule
{
    /** Both the MCP-bridge names and the session-visible names carry these args. */
    private const VERBS = [
        'woocommerce/product-create',
        'woocommerce/product-update',
        'woocommerce/products-create',
        'woocommerce/products-update',
    ];

    /** Statuses that put the product in front of customers. */
    private const PUBLISHING_STATUSES = ['publish', 'future'];

    /** Meta keys that write price or stock state directly, bypassing the top-level props. */
    private const GUARDED_META_KEYS = ['_price', '_regular_price', '_sale_price', '_stock', '_manage_stock'];

    /** @param array<string, mixed> $args */
    public function apply(string $verb, array $args, Tier $currentTier): ?Tier
    {
        if (!in_array($verb, self::VERBS, true)) {
            return null;
        }

        if (self::setsAPrice($args) || self::touchesGuardedMeta($args) || self::publishes($args)) {
            return Tier::Irreversible;
        }

        return null;
    }

    /** @param array<string, mixed> $args */
    private static function setsAPrice(array $args): bool
    {
        foreach (['regular_price', 'sale_price'] as $key) {
            if (array_key_exists($key, $args) && $args[$key] !== null) {
                return true;
            }
        }

        return false;
    }

    /**
     * The product REST schema accepts `meta_data: [{key, value}]` and applies it with
     * update_meta_data(), so `_regular_price` there writes the price without the prop.
     *
     * @param array<string, mixed> $args
     */
    private static function touchesGuardedMeta(array $args): bool
    {
        $meta = $args['meta_data'] ?? null;
        if (!is_array($meta)) {
            return false;
        }

        foreach ($meta as $entry) {
            $key = is_array($entry) ? ($entry['key'] ?? null) : null;
            if (is_string($key) && in_array(strtolower(trim($key)), self::GUARDED_META_KEYS, true)) {
                return true;
            }
        }

        return false;
    }

    /** @param array<string, mixed> $args */
    private static function publishes(array $args): bool
    {
        $status = is_string($args['status'] ?? null) ? strtolower(trim($args['status'])) : null;

        return $status !== null && in_array($status, self::PUBLISHING_STATUSES, true);
    }
}
