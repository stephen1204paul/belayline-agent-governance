<?php

declare(strict_types=1);

namespace Belayline\Plugin\Integrations\FluentCart;

use Belayline\Policy\Tier;

/**
 * OUR tier assignments for every `fluent-cart/` ability FluentCart 1.7.0
 * registers (33: 26 read/query, 7 write; read from `app/Modules/MCP/Tools/*.php`).
 * Authoritative: FluentCart's own readonly/destructive annotations are hints
 * only. No wildcard entries on purpose -- an ability FluentCart (or its Pro
 * add-on, via `fluent_cart/mcp_ability_names`) adds later is refused as
 * `unknown_verb` until a human classifies it here and extends the fixture test.
 *
 * Spec: SPEC-COMMERCE-INTEGRATIONS.md section 3.2. `Tier::Irreversible` is the
 * enum's top tier (value 2, approval-gated); "ungrantable" is enforced on top of
 * it by {@see FluentCartIntegration::grantEligible()}.
 */
final class FluentCartVerbCatalog
{
    /** @var array<string, Tier> */
    public const MAP = [
        // Reads and queries: Tier 0.
        'fluent-cart/get-store-context'      => Tier::Reversible,
        'fluent-cart/list-reference-data'    => Tier::Reversible,
        'fluent-cart/get-search-schema'      => Tier::Reversible,
        'fluent-cart/list-orders'            => Tier::Reversible,
        'fluent-cart/list-customers'         => Tier::Reversible,
        'fluent-cart/list-products'          => Tier::Reversible,
        'fluent-cart/list-subscriptions'     => Tier::Reversible,
        'fluent-cart/list-coupons'           => Tier::Reversible,
        'fluent-cart/list-transactions'      => Tier::Reversible,
        'fluent-cart/get-inventory'          => Tier::Reversible,
        'fluent-cart/get-order'              => Tier::Reversible,
        'fluent-cart/get-order-activity'     => Tier::Reversible,
        'fluent-cart/get-customer'           => Tier::Reversible,
        'fluent-cart/get-product'            => Tier::Reversible,
        'fluent-cart/get-subscription'       => Tier::Reversible,
        'fluent-cart/get-product-financials' => Tier::Reversible,
        'fluent-cart/get-sales-report'       => Tier::Reversible,
        'fluent-cart/get-sales-trend'        => Tier::Reversible,
        'fluent-cart/get-top-products'       => Tier::Reversible,
        'fluent-cart/get-refund-report'      => Tier::Reversible,
        'fluent-cart/get-upcoming-payments'  => Tier::Reversible,
        'fluent-cart/query-orders'           => Tier::Reversible,
        'fluent-cart/query-products'         => Tier::Reversible,
        'fluent-cart/query-customers'        => Tier::Reversible,
        'fluent-cart/query-subscriptions'    => Tier::Reversible,
        'fluent-cart/query-sources'          => Tier::Reversible,

        // Writes.
        'fluent-cart/apply-labels'       => Tier::SideEffecting,
        'fluent-cart/add-order-note'     => Tier::SideEffecting, // customer-visible => Tier 2 (CustomerVisibleNoteElevationRule); 1.7.0 notes are staff-only
        'fluent-cart/upsert-customer'    => Tier::SideEffecting, // new_email (rename) => Tier 2 (CustomerEmailChangeElevationRule)
        'fluent-cart/manage-coupon'      => Tier::SideEffecting, // enables / edits an active coupon => Tier 2 (CouponActivationElevationRule)
        'fluent-cart/change-order-status' => Tier::Irreversible, // emails, stock, integrations fire; not undoable
        'fluent-cart/refund-order'       => Tier::Irreversible, // gateway money movement; ungrantable
        'fluent-cart/change-subscription-status' => Tier::Irreversible, // gateway cancel; ungrantable
    ];
}
