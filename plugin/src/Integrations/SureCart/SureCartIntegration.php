<?php

declare(strict_types=1);

namespace Specflux\AgentSafety\Plugin\Integrations\SureCart;

use Specflux\AgentSafety\Packs\Pack;
use Specflux\AgentSafety\Plugin\Approval\StateProbe;
use Specflux\AgentSafety\Plugin\Changes\ChangeAdapters;
use Specflux\AgentSafety\Policy\ElevationRule;
use Specflux\AgentSafety\Policy\VerbCatalog;

/**
 * The one place SureCart coupling lives (governance, starter packs, and remote change capture/restore).
 * Wired from the plugin bootstrap ONLY when {@see available()}.
 */
final class SureCartIntegration
{
    /**
     * Oldest SureCart whose ability surface this catalog was verified against.
     * 4.9.3 is the only version read (source, 2026-10-07): the Abilities
     * classes, the `surecart/` names, and the edit/delete registration toggles
     * all come from that release. Older releases may lack abilities, and
     * governing an unread version would be guessing, so they are not governed.
     */
    public const MIN_VERSION = '4.9.3';

    public const NAMESPACE_PREFIX = 'surecart/';

    /**
     * True when SureCart is loaded and its version is known and >= MIN_VERSION.
     * SureCart defines no version constant; the version lives in the plugin
     * header and is exposed by `SureCart::plugin()->version()`. An unreadable
     * version counts as "too old": fail closed.
     */
    public static function available(): bool
    {
        $version = self::detectVersion();

        return $version !== null && version_compare($version, self::MIN_VERSION, '>=');
    }

    public static function detectVersion(): ?string
    {
        // Class name held in a variable: SureCart is not a dependency of this repo.
        $class = 'SureCart';
        if (!class_exists($class) || !method_exists($class, 'plugin')) {
            return null;
        }

        try {
            /** @var mixed $plugin */
            $plugin = $class::plugin();
            /** @var mixed $version */
            $version = is_object($plugin) && method_exists($plugin, 'version') ? $plugin->version() : null;
        } catch (\Throwable) {
            return null;
        }

        return is_string($version) && preg_match('/^\d+(\.\d+)*/', $version) === 1 ? $version : null;
    }

    /**
     * @param (callable(string, string): (array<string, mixed>|null))|null $fetcher Test seam for the probe's remote GET.
     * @return array{elevationRules: list<ElevationRule>, packs: list<Pack>, governedNamespaces: list<string>, stateProbes: array<string, StateProbe>}
     */
    public static function register(VerbCatalog $catalog, ?callable $fetcher = null, ?SureCartChangeAdapter $changes = null): array
    {
        $catalog->register(SureCartVerbCatalog::MAP);

        // Standing grants (agent_safety_enable_grants + eligibility filter) must
        // never cover refunds, cancellations or deletes. Run last so no other
        // eligibility callback can re-allow them.
        if (function_exists('add_filter')) {
            add_filter('agent_safety_grant_eligible', [self::class, 'denyUngrantable'], PHP_INT_MAX, 3);
        }

        // Change capture/restore for SureCart's remote objects joins the adapter registry.
        $adapter = $changes ?? new SureCartChangeAdapter();
        if (function_exists('add_filter')) {
            add_filter(ChangeAdapters::FILTER, static function ($adapters) use ($adapter) {
                return is_array($adapters) ? [...$adapters, $adapter] : $adapters;
            });
        }

        $probe = new SureCartStateProbe($fetcher);
        $probes = [];
        foreach (array_keys(SureCartStateProbe::TARGETS) as $verb) {
            $probes[$verb] = $probe;
        }

        return [
            'elevationRules' => [
                new PriceElevationRule(),
                new FulfillmentNotificationElevationRule(),
                new DiscountElevationRule(),
            ],
            'packs' => SureCartPacks::all(),
            'governedNamespaces' => [self::NAMESPACE_PREFIX],
            'stateProbes' => $probes,
        ];
    }

    /**
     * agent_safety_grant_eligible callback: ungrantable verbs are never eligible.
     *
     * @param mixed $eligible
     * @param mixed $grant
     * @param mixed $verb
     * @return mixed
     */
    public static function denyUngrantable($eligible, $grant = null, $verb = null)
    {
        return is_string($verb) && in_array($verb, SureCartVerbCatalog::UNGRANTABLE, true) ? false : $eligible;
    }
}
