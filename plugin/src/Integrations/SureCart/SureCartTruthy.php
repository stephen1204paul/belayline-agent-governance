<?php

declare(strict_types=1);

namespace Belayline\Plugin\Integrations\SureCart;

/**
 * Mirrors how SureCart's abilities read their own inputs, so a rule can never
 * disagree with what SureCart will actually do. SureCart casts flags with
 * `(bool) $input[...]` (so the string "false" is TRUE to it) and reads amounts
 * with absint()/floatval(). Anything non-scalar is treated as "set".
 */
final class SureCartTruthy
{
    /** True when SureCart would treat the flag as on. Missing/null is off. */
    public static function flagOn(mixed $value): bool
    {
        if ($value === null) {
            return false;
        }

        return is_scalar($value) ? (bool) $value : true;
    }

    /**
     * True when the key is present with a non-null value (SureCart's isset()).
     *
     * @param array<string, mixed> $args
     */
    public static function isSet(array $args, string $key): bool
    {
        return array_key_exists($key, $args) && $args[$key] !== null;
    }
}
