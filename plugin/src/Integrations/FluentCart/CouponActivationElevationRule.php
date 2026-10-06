<?php

declare(strict_types=1);

namespace Specflux\AgentSafety\Plugin\Integrations\FluentCart;

use Specflux\AgentSafety\Policy\ElevationRule;
use Specflux\AgentSafety\Policy\Tier;

/**
 * Coupon changes are effective at checkout immediately, so only work on a
 * coupon that stays disabled is Tier 1; anything that enables a coupon or
 * edits a live one is Tier 2 (spec 3.2).
 *
 * - `deactivate`: stays Tier 1.
 * - `create`: FluentCart defaults a new coupon to `active` when `status` is
 *   absent (CouponTools.php, "default a new coupon to active"), so only an
 *   explicit `status: inactive` is Tier 1.
 * - `update` (and any unrecognised or missing action): explicit `status:
 *   inactive` is Tier 1; `active` is Tier 2; with no `status` the coupon's
 *   CURRENT status decides, read through the injected resolver
 *   (`fct_coupons.status` by `id`). A missing resolver, an unreadable or
 *   non-`inactive` status, or a missing `coupon_id` all elevate: fail closed.
 */
final class CouponActivationElevationRule implements ElevationRule
{
    /** @var (callable(int): ?string)|null */
    private $currentStatus;

    /** @param (callable(int): ?string)|null $currentStatus Returns the coupon's stored status, null when unreadable. */
    public function __construct(?callable $currentStatus = null)
    {
        $this->currentStatus = $currentStatus;
    }

    /** @param array<string, mixed> $args */
    public function apply(string $verb, array $args, Tier $currentTier): ?Tier
    {
        if ($verb !== 'fluent-cart/manage-coupon') {
            return null;
        }

        $action = is_string($args['action'] ?? null) ? strtolower(trim($args['action'])) : '';
        if ($action === 'deactivate') {
            return null;
        }

        $status = is_string($args['status'] ?? null) ? strtolower(trim($args['status'])) : null;
        if ($status === 'inactive') {
            return null;
        }
        if ($status !== null && $status !== '') {
            return Tier::Irreversible; // active, or anything we do not recognise
        }

        if ($action === 'create') {
            return Tier::Irreversible; // no status => FluentCart creates it active
        }

        $id = isset($args['coupon_id']) && is_numeric($args['coupon_id']) ? (int) $args['coupon_id'] : 0;
        if ($id < 1 || $this->currentStatus === null) {
            return Tier::Irreversible;
        }

        return ($this->currentStatus)($id) === 'inactive' ? null : Tier::Irreversible;
    }
}
