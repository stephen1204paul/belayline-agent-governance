<?php

declare(strict_types=1);

namespace Belayline\Plugin\Integrations\FluentCart;

/**
 * `change-subscription-status` (cancel; takes `subscription_id`,
 * SubscriptionTools.php:88-110). Reads `{prefix}fct_subscriptions`: `status`,
 * `next_billing_date`, `canceled_at`, `vendor_subscription_id` (the gateway
 * object that would be cancelled) and `updated_at`.
 */
final class FluentCartSubscriptionStateProbe extends FluentCartRowProbe
{
    protected function table(): string
    {
        return 'fct_subscriptions';
    }

    protected function selectByIdSql(): string
    {
        return 'SELECT `status`, `next_billing_date`, `canceled_at`, `vendor_subscription_id`, `updated_at` FROM %i WHERE `id` = %d LIMIT 1';
    }

    protected function idArg(string $verb): string
    {
        return 'subscription_id';
    }
}
