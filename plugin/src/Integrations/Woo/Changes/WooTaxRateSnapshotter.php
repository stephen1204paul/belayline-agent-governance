<?php

declare(strict_types=1);

namespace Specflux\AgentSafety\Plugin\Integrations\Woo\Changes;

use Specflux\AgentSafety\Changes\ChangeSnapshotter;
use Specflux\AgentSafety\Changes\SnapshotRef;

/**
 * Tax rates are not WC_Data objects: WC_Tax writes the table directly and its
 * only hooks fire after the old row is gone. For a tax ability that names an
 * existing rate (`id`), the row is read BEFORE and AFTER the call and the diff
 * recorded. A rate created by the call has no id yet; the capture adapter sees
 * it on `woocommerce_tax_rate_added`. WooCommerce ships no tax ability today,
 * so the ids matched are the REST bridge's naming (`woocommerce/taxes-*`) and
 * SenroFlux's `senroflux/tax-rate-*`.
 */
final class WooTaxRateSnapshotter implements ChangeSnapshotter
{
    private const ABILITIES = '#^(woocommerce/(taxes|tax-rates?)-(update|delete)|senroflux/tax-rate-(save|update|delete))$#';

    public function handles(string $ability): bool
    {
        return preg_match(self::ABILITIES, $ability) === 1;
    }

    public function refs(string $ability, array $args): array
    {
        $id = $args['id'] ?? $args['tax_rate_id'] ?? null;
        if (!is_numeric($id) || (int) $id <= 0) {
            return [];
        }

        return [new SnapshotRef(WooEntityState::TAX_RATE, (string) (int) $id)];
    }

    public function snapshot(SnapshotRef $ref): ?array
    {
        return WooEntityState::taxRate((int) $ref->objectId);
    }
}
