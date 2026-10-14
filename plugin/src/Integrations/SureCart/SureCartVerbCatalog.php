<?php

declare(strict_types=1);

namespace Belayline\Plugin\Integrations\SureCart;

use Belayline\Policy\Tier;

/**
 * OUR tier assignments for the 54 abilities SureCart 4.9.3 registers under
 * `surecart/` (fixture: tests/Fixtures/surecart-4.9.3-abilities.json, generated
 * from app/src/Abilities/Abilities/*.php). Authoritative: SureCart's own
 * readonly/destructive/idempotent annotations may only TIGHTEN a decision
 * (VerdictPipeline), never loosen what is mapped here — SureCart marks
 * archive-product and update-price as non-destructive, we do not.
 *
 * The catalog lists every ability SureCart can register. SureCart's own edit
 * and delete toggles (`surecart_mcp_edit_abilities_enabled`,
 * `surecart_mcp_delete_abilities_enabled`) simply leave those abilities
 * unregistered; a catalog entry for an absent ability is inert, so toggles
 * never break anything. Anything NOT listed is refused as unknown_verb.
 *
 * Tier 2 and "Irreversible" in the spec both map to Tier::Irreversible (the
 * enum has three cases); {@see self::UNGRANTABLE} marks the subset that must
 * never be satisfied by a standing grant ({@see SureCartIntegration::register()}).
 */
final class SureCartVerbCatalog
{
    /** @var array<string, Tier> */
    public const MAP = [
        // Reads: Tier 0.
        'surecart/get-store-info'                  => Tier::Reversible,
        'surecart/get-store-dashboard'             => Tier::Reversible,
        'surecart/list-products'                   => Tier::Reversible,
        'surecart/get-product'                     => Tier::Reversible,
        'surecart/list-orders'                     => Tier::Reversible,
        'surecart/get-order'                       => Tier::Reversible,
        'surecart/get-order-statistics'            => Tier::Reversible,
        'surecart/filter-orders'                   => Tier::Reversible,
        'surecart/get-orders-filter-schema'        => Tier::Reversible,
        'surecart/list-abandoned-checkouts'        => Tier::Reversible,
        'surecart/get-abandoned-checkout'          => Tier::Reversible,
        'surecart/list-customers'                  => Tier::Reversible,
        'surecart/get-customer'                    => Tier::Reversible,
        'surecart/filter-customers'                => Tier::Reversible,
        'surecart/get-customers-filter-schema'     => Tier::Reversible,
        'surecart/list-coupons'                    => Tier::Reversible,
        'surecart/get-coupon'                      => Tier::Reversible,
        'surecart/list-promotions'                 => Tier::Reversible,
        'surecart/get-promotion'                   => Tier::Reversible,
        'surecart/list-prices'                     => Tier::Reversible,
        'surecart/list-subscriptions'              => Tier::Reversible,
        'surecart/get-subscription'                => Tier::Reversible,
        'surecart/filter-subscriptions'            => Tier::Reversible,
        'surecart/get-subscriptions-filter-schema' => Tier::Reversible,
        'surecart/list-fulfillments'               => Tier::Reversible,
        'surecart/get-fulfillment'                 => Tier::Reversible,
        'surecart/get-fulfillment-item'            => Tier::Reversible,
        'surecart/list-refunds'                    => Tier::Reversible,
        'surecart/get-refund'                      => Tier::Reversible,
        'surecart/list-licenses'                   => Tier::Reversible,
        'surecart/get-license'                     => Tier::Reversible,

        // Create/update of products, customers, coupons, promotions, fulfillments: Tier 1.
        'surecart/create-product'     => Tier::SideEffecting,
        'surecart/update-product'     => Tier::SideEffecting,
        'surecart/duplicate-product'  => Tier::SideEffecting,
        'surecart/create-customer'    => Tier::SideEffecting,
        'surecart/update-customer'    => Tier::SideEffecting,
        'surecart/create-coupon'      => Tier::SideEffecting, // percent_off >= 50 or amount_off above the threshold elevates (DiscountElevationRule)
        'surecart/update-coupon'      => Tier::SideEffecting, // same rule
        'surecart/create-promotion'   => Tier::SideEffecting,
        'surecart/update-promotion'   => Tier::SideEffecting,
        'surecart/create-fulfillment' => Tier::SideEffecting, // notifications_enabled elevates (FulfillmentNotificationElevationRule)
        'surecart/update-fulfillment' => Tier::SideEffecting, // same rule
        'surecart/update-price'       => Tier::SideEffecting, // amount change or archive elevates (PriceElevationRule)

        // Tier 2 (approval, parks): customer-facing money or lifecycle effects.
        // create-price is not named in SPEC 3.3; a new price changes what customers
        // can be charged, so it starts at Tier 2 rather than relying on a rule.
        'surecart/create-price'                     => Tier::Irreversible,
        'surecart/archive-product'                  => Tier::Irreversible,
        'surecart/create-invoice'                   => Tier::Irreversible,
        'surecart/update-invoice'                   => Tier::Irreversible,
        'surecart/update-subscription-renewal-date' => Tier::Irreversible,

        // Irreversible AND ungrantable (see UNGRANTABLE).
        'surecart/create-refund'       => Tier::Irreversible,
        'surecart/cancel-subscription' => Tier::Irreversible,
        'surecart/delete-coupon'       => Tier::Irreversible,
        'surecart/delete-customer'     => Tier::Irreversible,
        'surecart/delete-fulfillment'  => Tier::Irreversible,
        'surecart/delete-promotion'    => Tier::Irreversible,
    ];

    /**
     * Verbs that park every time: no standing grant may ever satisfy them.
     *
     * @var list<string>
     */
    public const UNGRANTABLE = [
        'surecart/create-refund',
        'surecart/cancel-subscription',
        'surecart/delete-coupon',
        'surecart/delete-customer',
        'surecart/delete-fulfillment',
        'surecart/delete-promotion',
    ];
}
