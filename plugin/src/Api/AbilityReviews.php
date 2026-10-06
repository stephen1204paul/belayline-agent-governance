<?php

declare(strict_types=1);

namespace Specflux\AgentSafety\Plugin\Api;

use Specflux\AgentSafety\Plugin\Reviews\ReviewTier;
use Specflux\AgentSafety\Plugin\Support\AdminChangeRecorder;
use Specflux\AgentSafety\Policy\VerbCatalog;

/**
 * Ability review (0.5): an administrator classifies a third-party ability
 * without code, and SenroGate governs it from then on.
 *
 * Storage is the `agsafe_ability_reviews` option, ability id => {tier,
 * schema (sha256 of the input schema at review time), reviewed_by,
 * reviewed_at}. Everything else is derived from it:
 *
 *   - {@see namespaces()} feeds the governed-namespace list, so reviewing ONE
 *     ability governs its whole namespace (the unreviewed siblings then fail
 *     closed as `unknown_verb`);
 *   - {@see observe()} runs at registration (`wp_register_ability_args`, before
 *     the gate wraps the ability) and maps the verb into the catalog ONLY when
 *     the live input schema still hashes to the stored one. A changed schema
 *     leaves the verb unmapped, so the call is refused `unknown_verb` until a
 *     human reviews it again;
 *   - {@see status()} is the public contract SenroFlux probes:
 *     'reviewed' | 'unreviewed' | 'blocked'.
 *
 * What an ability says about itself never loosens anything here: the stored
 * tier still passes through the destructive-hint check in the verdict pipeline.
 */
final class AbilityReviews
{
    public const OPTION = 'agsafe_ability_reviews';

    public const REVIEWED = 'reviewed';
    public const UNREVIEWED = 'unreviewed';
    public const BLOCKED = 'blocked';

    /** @var array<string, string> ability => input-schema hash seen at registration this request */
    private array $observed = [];

    public function __construct(private readonly ?VerbCatalog $catalog = null)
    {
    }

    /** Hook the registration-time half. Must run before abilities register. */
    public function register(): void
    {
        // Priority 5: before the permission gate wraps the ability at 10.
        add_filter('wp_register_ability_args', [$this, 'observe'], 5, 2);
        // Irreversible reviews are ungrantable whatever else a site's eligibility
        // filter says: PHP_INT_MAX makes this the last word, and it can only say no.
        add_filter('agent_safety_grant_eligible', [$this, 'grantEligible'], PHP_INT_MAX, 3);
    }

    /**
     * The public contract: exactly 'reviewed', 'unreviewed' or 'blocked'.
     * A review whose input schema has changed since it was made reads
     * 'unreviewed'.
     */
    public function status(string $ability): string
    {
        $entry = $this->entries()[$ability] ?? null;
        if ($entry === null || $this->drifted($ability, $entry['schema'])) {
            return self::UNREVIEWED;
        }

        return $entry['tier'] === ReviewTier::Blocked ? self::BLOCKED : self::REVIEWED;
    }

    /** The stored tier of a current (non-drifted) review, else null. */
    public function tierOf(string $ability): ?ReviewTier
    {
        $entry = $this->entries()[$ability] ?? null;

        return $entry === null || $this->drifted($ability, $entry['schema']) ? null : $entry['tier'];
    }

    /**
     * Every stored review, malformed rows dropped.
     *
     * @return array<string, array{tier: ReviewTier, schema: string, reviewed_by: int, reviewed_at: string}>
     */
    public function entries(): array
    {
        $raw = function_exists('get_option') ? get_option(self::OPTION, []) : [];
        if (!is_array($raw)) {
            return [];
        }

        $clean = [];
        foreach ($raw as $ability => $row) {
            if (!is_string($ability) || $ability === '' || !is_array($row)) {
                continue;
            }
            $tier = is_string($row['tier'] ?? null) ? ReviewTier::tryFrom($row['tier']) : null;
            if ($tier === null || !is_string($row['schema'] ?? null)) {
                continue;
            }
            $clean[$ability] = [
                'tier' => $tier,
                'schema' => $row['schema'],
                'reviewed_by' => (int) ($row['reviewed_by'] ?? 0),
                'reviewed_at' => is_string($row['reviewed_at'] ?? null) ? $row['reviewed_at'] : '',
            ];
        }

        return $clean;
    }

    /**
     * Namespace prefixes ("acme/") of every stored review, drifted and blocked
     * ones included: a namespace stays governed (fail closed) even when its
     * only review needs redoing.
     *
     * @return list<string>
     */
    public function namespaces(): array
    {
        $out = [];
        foreach (array_keys($this->entries()) as $ability) {
            $prefix = self::namespaceOf($ability);
            if ($prefix !== '') {
                $out[$prefix] = true;
            }
        }

        return array_keys($out);
    }

    /**
     * Abilities the `reviewed-abilities` pack admits: every stored review that
     * is not Blocked. A drifted one is listed too, but is unmapped in the
     * catalog, so the gate refuses it before the pack is consulted.
     *
     * @return list<string>
     */
    public function admittedVerbs(): array
    {
        $out = [];
        foreach ($this->entries() as $ability => $entry) {
            if ($entry['tier'] !== ReviewTier::Blocked) {
                $out[] = $ability;
            }
        }

        return $out;
    }

    /** "acme/thing-list" => "acme/"; no slash => "". */
    public static function namespaceOf(string $ability): string
    {
        $pos = strpos($ability, '/');

        return $pos === false || $pos === 0 ? '' : substr($ability, 0, $pos + 1);
    }

    /**
     * Stable fingerprint of an ability's input schema: key order never matters,
     * list order does. Absent and non-array schemas hash as the empty schema.
     *
     * @param mixed $schema
     */
    public static function schemaHash($schema): string
    {
        $json = wp_json_encode(self::canonical(is_array($schema) ? $schema : []));

        return hash('sha256', is_string($json) ? $json : '');
    }

    /**
     * Registration-time half. Hashes the live schema, remembers it for
     * {@see status()}, and maps the verb to its stored tier only while the
     * review still matches. Never alters the registration args.
     *
     * @param array<string, mixed> $args
     * @return array<string, mixed>
     */
    public function observe(array $args, string $name): array
    {
        $entry = $this->entries()[$name] ?? null;
        if ($entry === null) {
            return $args;
        }

        $hash = self::schemaHash($args['input_schema'] ?? null);
        $this->observed[$name] = $hash;

        $tier = $entry['tier']->tier();
        if ($hash === $entry['schema'] && $tier !== null) {
            $this->catalog?->register([$name => $tier]);
        }

        return $args;
    }

    /**
     * `agent_safety_grant_eligible`: an Irreversible review is never eligible
     * for a pre-approval grant. Passes every other verb's answer through.
     *
     * @param mixed $eligible
     * @param mixed $grant
     * @param mixed $verb
     * @return mixed
     */
    public function grantEligible($eligible, $grant = null, $verb = null)
    {
        if (is_string($verb) && ($this->entries()[$verb]['tier'] ?? null) === ReviewTier::Irreversible) {
            return false;
        }

        return $eligible;
    }

    /**
     * Persist one namespace's choices. $choices maps ability => tier value
     * ('' = not reviewed); $currentSchemas maps ability => the live input
     * schema hash, taken server-side from the registry, never from the form.
     * Abilities outside $currentSchemas are ignored. Returns the number of
     * rows added, changed or removed.
     *
     * @param array<string, string>  $choices
     * @param array<string, string>  $currentSchemas
     */
    public function apply(array $choices, array $currentSchemas, int $userId, ?AdminChangeRecorder $audit = null): int
    {
        $entries = $this->entries();
        $stored = function_exists('get_option') ? get_option(self::OPTION, []) : [];
        $stored = is_array($stored) ? $stored : [];
        $changed = 0;

        foreach ($choices as $ability => $value) {
            if (!isset($currentSchemas[$ability])) {
                continue;
            }
            $tier = $value === '' ? null : ReviewTier::tryFrom($value);
            if ($value !== '' && $tier === null) {
                continue;
            }
            $before = $entries[$ability]['tier'] ?? null;
            $beforeSchema = $entries[$ability]['schema'] ?? null;

            if ($tier === null) {
                if ($before === null) {
                    continue;
                }
                unset($stored[$ability]);
            } else {
                if ($before === $tier && $beforeSchema === $currentSchemas[$ability]) {
                    continue;
                }
                $stored[$ability] = [
                    'tier' => $tier->value,
                    'schema' => $currentSchemas[$ability],
                    'reviewed_by' => $userId,
                    'reviewed_at' => gmdate('Y-m-d H:i:s'),
                ];
            }

            $changed++;
            $audit?->abilityReviewed($ability, $before?->value, $tier?->value);
        }

        if ($changed > 0) {
            update_option(self::OPTION, $stored, false);
        }

        return $changed;
    }

    /** True when the live schema (seen at registration, else read from the registry) differs from the stored hash. */
    private function drifted(string $ability, string $storedHash): bool
    {
        $live = $this->observed[$ability] ?? null;
        if ($live === null && function_exists('wp_get_ability')) {
            $registered = wp_get_ability($ability);
            if ($registered !== null) {
                $live = self::schemaHash($registered->get_input_schema());
            }
        }

        // Not registered (yet): nothing to compare, the stored review stands.
        // It cannot be called while unregistered, and registration re-checks.
        return $live !== null && $live !== $storedHash;
    }

    /**
     * @param array<mixed> $value
     * @return array<mixed>
     */
    private static function canonical(array $value): array
    {
        foreach ($value as $k => $v) {
            if (is_array($v)) {
                $value[$k] = self::canonical($v);
            }
        }
        if (!array_is_list($value)) {
            ksort($value);
        }

        return $value;
    }
}
