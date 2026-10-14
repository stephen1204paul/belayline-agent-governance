<?php

declare(strict_types=1);

namespace Belayline\Plugin\Integrations\FluentCart;

/**
 * `manage-coupon`. Update and deactivate carry `coupon_id`; reads
 * `{prefix}fct_coupons`: `status`, `type`, `amount` (cents when fixed, percent
 * otherwise), `end_date` and `updated_at`. `use_count` is deliberately left out:
 * every checkout bumps it, which would stale every pending coupon approval.
 *
 * `create` has no target yet, so it gets a constant fact set instead of the
 * null that would refuse it as `state_unverifiable`.
 */
final class FluentCartCouponStateProbe extends FluentCartRowProbe
{
    protected function table(): string
    {
        return 'fct_coupons';
    }

    protected function selectByIdSql(): string
    {
        return 'SELECT `status`, `type`, `amount`, `end_date`, `updated_at` FROM %i WHERE `id` = %d LIMIT 1';
    }

    protected function idArg(string $verb): string
    {
        return 'coupon_id';
    }

    public function read(string $verb, array $args): ?array
    {
        if (self::positiveInt($args['coupon_id'] ?? null) === null && ($args['action'] ?? null) === 'create') {
            return ['target' => 'new'];
        }

        return $this->row($verb, $args);
    }
}
