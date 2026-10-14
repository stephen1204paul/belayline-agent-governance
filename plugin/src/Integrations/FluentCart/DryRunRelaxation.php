<?php

declare(strict_types=1);

namespace Belayline\Plugin\Integrations\FluentCart;

/**
 * Spec 3.2: "`dry_run: true` on any write: Tier 0 -- FluentCart's own WriteGuard
 * does the dry run." NOT ENABLED. The spec requires the wp-env smoke (stage C3)
 * to prove nothing is written on a dry run before this ships, and until then
 * the elevation applies whatever `dry_run` says.
 *
 * TODO(SPEC-COMMERCE-INTEGRATIONS.md section 6, C3): after the smoke passes, flip
 * {@see self::ENABLED} and wire {@see self::applies()} into the verdict path.
 * Wiring needs a core change: an ElevationRule can only RAISE a tier
 * (TierClassifier refuses to lower), so a relaxation cannot be a rule.
 */
final class DryRunRelaxation
{
    public const ENABLED = false;

    /**
     * True only when the relaxation is on AND the call is a strict preview:
     * `dry_run` is the boolean true (not a truthy string) and no
     * `confirm_token` is present (a token means an execute attempt).
     *
     * @param array<string, mixed> $args
     */
    public static function applies(string $verb, array $args, bool $enabled = self::ENABLED): bool
    {
        if (!$enabled || !isset(FluentCartVerbCatalog::MAP[$verb])) {
            return false;
        }
        if (!FluentCartVerbCatalog::MAP[$verb]->isWrite()) {
            return false;
        }

        $token = $args['confirm_token'] ?? null;

        return ($args['dry_run'] ?? null) === true && ($token === null || $token === '');
    }
}
