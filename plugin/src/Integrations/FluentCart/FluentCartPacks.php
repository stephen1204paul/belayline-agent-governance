<?php

declare(strict_types=1);

namespace Belayline\Plugin\Integrations\FluentCart;

use Belayline\Packs\Pack;
use Belayline\Policy\Tier;

/**
 * FluentCart starter packs, registered like {@see \Belayline\Plugin\Integrations\Woo\WooPacks}:
 *   - fluentcart-default-agent     — every `fluent-cart/` verb, but each Tier-2
 *                                    verb (order status, refund, subscription
 *                                    cancel) waits for a human approval.
 *   - fluentcart-readonly-analyst  — the read/query verbs only (derived from the
 *                                    catalog's Tier 0 entries, so a new read verb
 *                                    needs a human classification first) and both
 *                                    write classes hard-denied.
 */
final class FluentCartPacks
{
    /** @return list<Pack> */
    public static function all(): array
    {
        return [
            new Pack(
                name: 'fluentcart-default-agent',
                allow: ['fluent-cart/*'],
                approvalByClass: ['tier2' => true],
            ),
            new Pack(
                name: 'fluentcart-readonly-analyst',
                allow: self::readVerbs(),
                denyClass: ['tier1', 'tier2'],
            ),
        ];
    }

    /** @return list<string> */
    public static function readVerbs(): array
    {
        $verbs = [];
        foreach (FluentCartVerbCatalog::MAP as $verb => $tier) {
            if ($tier === Tier::Reversible) {
                $verbs[] = $verb;
            }
        }

        return $verbs;
    }
}
