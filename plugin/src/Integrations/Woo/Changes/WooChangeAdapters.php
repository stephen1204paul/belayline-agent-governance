<?php

declare(strict_types=1);

namespace Belayline\Plugin\Integrations\Woo\Changes;

use Belayline\Plugin\Changes\ChangeAdapters;

/**
 * The WooCommerce adapters, appended to the registry through
 * `belayline_change_adapters`. Wired by the plugin bootstrap only inside
 * the WooIntegration::available() branch, so a site without WooCommerce never
 * loads them.
 */
final class WooChangeAdapters
{
    /** @return list<object> */
    public static function all(): array
    {
        return [new WooCaptureAdapter(), new WooRestoreAdapter(), new WooTaxRateSnapshotter()];
    }

    public static function register(): void
    {
        add_filter(ChangeAdapters::FILTER, static function ($adapters): array {
            return [...(is_array($adapters) ? $adapters : []), ...self::all()];
        });
    }
}
