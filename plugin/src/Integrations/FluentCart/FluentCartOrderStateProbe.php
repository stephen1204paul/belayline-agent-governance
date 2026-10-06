<?php

declare(strict_types=1);

namespace Specflux\AgentSafety\Plugin\Integrations\FluentCart;

/**
 * Order-targeting writes (`change-order-status`, `refund-order`; both take
 * `order_id`, OrderTools.php:135-199). Reads `{prefix}fct_orders`: `status`,
 * `payment_status`, `shipping_status` (the three lifecycle columns), `total_paid`
 * and `total_refund` (cents; a refund or payment landing in between makes an
 * approval to refund stale), `mode` (live/test) and `updated_at`.
 * `add-order-note` appends and deliberately has no probe.
 */
final class FluentCartOrderStateProbe extends FluentCartRowProbe
{
    protected function table(): string
    {
        return 'fct_orders';
    }

    protected function selectByIdSql(): string
    {
        return 'SELECT `status`, `payment_status`, `shipping_status`, `total_paid`, `total_refund`, `mode`, `updated_at` FROM %i WHERE `id` = %d LIMIT 1';
    }

    protected function idArg(string $verb): string
    {
        return 'order_id';
    }
}
