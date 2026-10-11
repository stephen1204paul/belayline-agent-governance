<?php

declare(strict_types=1);

namespace Belayline\Tests\Fixtures;

use Belayline\Policy\Tier;
use Belayline\Policy\VerbCatalog;

/**
 * Test-only stand-in for the moved `Belayline\Plugin\Integrations\Woo\WooVerbCatalog`
 * map (that class lives in the plugin package and is not reachable from core
 * tests). Mirrors it exactly so GateTest/TierClassifierTest keep exercising
 * realistic woocommerce/* fixtures against the now-generic core classes.
 */
final class WooLikeVerbCatalog
{
    public static function build(): VerbCatalog
    {
        $catalog = new VerbCatalog();
        $catalog->register([
            'woocommerce/products-list'   => Tier::Reversible,
            'woocommerce/products-get'    => Tier::Reversible,
            'woocommerce/orders-list'     => Tier::Reversible,
            'woocommerce/orders-get'      => Tier::Reversible,
            'woocommerce/products-create' => Tier::SideEffecting,
            'woocommerce/products-update' => Tier::SideEffecting,
            'woocommerce/products-delete' => Tier::SideEffecting,
            'woocommerce/orders-create'   => Tier::SideEffecting,
            'woocommerce/orders-update'   => Tier::SideEffecting,
            'woocommerce/orders-refund'   => Tier::Irreversible,
            'woocommerce/customers-email' => Tier::Irreversible,
            'woocommerce/reports-*'       => Tier::Reversible,
            'woocommerce/settings-*'      => Tier::SideEffecting,
        ]);

        return $catalog;
    }
}
