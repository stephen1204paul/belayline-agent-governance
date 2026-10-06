<?php

declare(strict_types=1);

namespace Specflux\AgentSafety\Plugin\Integrations\SureCart;

use Specflux\AgentSafety\Packs\Pack;
use Specflux\AgentSafety\Policy\Tier;

/**
 * SureCart starter packs, mirroring {@see \Specflux\AgentSafety\Plugin\Integrations\Woo\WooPacks}:
 *   - surecart-default-agent     — every SureCart ability, Tier 2 approval-gated.
 *   - surecart-readonly-analyst  — reads only (the Tier 0 catalog verbs), with
 *                                  both write classes hard-denied as well, so a
 *                                  future read-looking verb that writes still cannot pass.
 */
final class SureCartPacks
{
    /** @return list<Pack> */
    public static function all(): array
    {
        $reads = array_keys(array_filter(SureCartVerbCatalog::MAP, static fn (Tier $t): bool => $t === Tier::Reversible));
        sort($reads);

        return [
            new Pack(
                name: 'surecart-default-agent',
                allow: ['surecart/*'],
                approvalByClass: ['tier2' => true],
            ),
            new Pack(
                name: 'surecart-readonly-analyst',
                allow: $reads,
                denyClass: ['tier1', 'tier2'],
            ),
        ];
    }
}
