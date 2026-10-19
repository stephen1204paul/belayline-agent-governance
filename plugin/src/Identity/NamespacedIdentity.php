<?php

declare(strict_types=1);

namespace Belayline\Plugin\Identity;

/**
 * Read-time guard around an identity provider that another plugin contributed
 * through the `belayline_identity_providers` filter. Every token must be
 * namespaced (`prefix:value`) and its prefix must not be one Belayline itself
 * owns: a contributed provider can add its own kind of credential but can
 * never claim to be an application password, a person, a role or a WooCommerce
 * key. Offending tokens are dropped and the rest are kept (fail closed).
 */
final class NamespacedIdentity implements IdentityProvider
{
    /** Prefixes owned by Belayline and its bundled integrations. */
    public const RESERVED_PREFIXES = ['app', 'user', 'role', 'wc'];

    public function __construct(private readonly IdentityProvider $inner)
    {
    }

    public static function acceptable(mixed $token): bool
    {
        if (!is_string($token) || preg_match('/^([a-z][a-z0-9_-]*):(.+)$/D', $token, $m) !== 1) {
            return false;
        }

        return !in_array($m[1], self::RESERVED_PREFIXES, true);
    }

    /** @return list<string> */
    public function currentTokens(): array
    {
        return array_values(array_filter($this->inner->currentTokens(), self::acceptable(...)));
    }

    /** @return array<string, string> */
    public function bindableTokens(): array
    {
        $out = [];
        foreach ($this->inner->bindableTokens() as $token => $label) {
            if (self::acceptable($token)) {
                $out[(string) $token] = (string) $label;
            }
        }

        return $out;
    }

    public function label(): string
    {
        return $this->inner->label();
    }
}
