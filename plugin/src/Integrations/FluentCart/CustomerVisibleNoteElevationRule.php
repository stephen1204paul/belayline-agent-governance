<?php

declare(strict_types=1);

namespace Specflux\AgentSafety\Plugin\Integrations\FluentCart;

use Specflux\AgentSafety\Policy\ElevationRule;
use Specflux\AgentSafety\Policy\Tier;

/**
 * FluentCart 1.7.0's `add-order-note` is staff-only and declares just
 * `order_id` + `note` (OrderTools.php:157-174), and its wrapper rejects
 * undeclared params -- so today no argument can make a note customer-visible.
 * This rule is the fail-closed guard for the day one can: any argument that
 * names customer visibility elevates the call to Tier 2 (spec 3.2).
 */
final class CustomerVisibleNoteElevationRule implements ElevationRule
{
    private const FLAG_KEYS = [
        'customer_note', 'customer_visible', 'visible_to_customer', 'notify_customer',
        'send_to_customer', 'is_customer_note', 'public', 'is_public', 'email_customer',
    ];

    private const AUDIENCE_KEYS = ['type', 'visibility', 'audience', 'note_type'];
    private const CUSTOMER_AUDIENCES = ['customer', 'public', 'customer_note', 'customer-visible'];

    /** @param array<string, mixed> $args */
    public function apply(string $verb, array $args, Tier $currentTier): ?Tier
    {
        if ($verb !== 'fluent-cart/add-order-note') {
            return null;
        }

        foreach (self::FLAG_KEYS as $key) {
            if (!empty($args[$key]) && $args[$key] !== 'no' && $args[$key] !== 'false') {
                return Tier::Irreversible;
            }
        }
        foreach (self::AUDIENCE_KEYS as $key) {
            if (is_string($args[$key] ?? null) && in_array(strtolower(trim($args[$key])), self::CUSTOMER_AUDIENCES, true)) {
                return Tier::Irreversible;
            }
        }

        return null;
    }
}
