<?php

declare(strict_types=1);

namespace Belayline\Plugin\Integrations\SureCart;

use RuntimeException;

/**
 * The only code that talks to SureCart's remote API for capture/restore:
 * a cache-bypassing GET (shared with the state probe) and a PATCH through the
 * SureCart model's own `update()`. Both are injectable wherever they are used,
 * so nothing in the test suite touches the network.
 *
 * Contract of {@see get()}: the attributes array when the object exists, NULL
 * only when SureCart says it does not exist, and a thrown exception for every
 * other failure. Capture must never mistake an outage for an absent object.
 */
final class SureCartRemote
{
    /**
     * @return array<string, mixed>|null
     * @throws RuntimeException On any failure other than not-found.
     */
    public static function get(string $model, string $id): ?array
    {
        $class = 'SureCart\\Models\\' . $model;
        if (!class_exists($class)) {
            throw new RuntimeException('SureCart model unavailable: ' . esc_html($model));
        }

        /** @var mixed $found */
        $found = $class::where(['cached' => false])->find($id);
        if (self::isError($found)) {
            if (self::isNotFound($found)) {
                return null;
            }
            throw new RuntimeException('SureCart GET failed: ' . esc_html(self::message($found)));
        }
        if (!is_object($found) || !method_exists($found, 'toArray')) {
            throw new RuntimeException('SureCart GET returned no object');
        }

        /** @var mixed $attrs */
        $attrs = $found->toArray();
        if (!is_array($attrs)) {
            throw new RuntimeException('SureCart GET returned no attributes');
        }

        return $attrs;
    }

    /**
     * PATCH only the given fields.
     *
     * @param array<string, mixed> $fields
     * @throws RuntimeException When SureCart refuses.
     */
    public static function update(string $model, string $id, array $fields): void
    {
        $class = 'SureCart\\Models\\' . $model;
        if (!class_exists($class)) {
            throw new RuntimeException('SureCart model unavailable: ' . esc_html($model));
        }

        /** @var mixed $updated */
        $updated = $class::update(['id' => $id] + $fields);
        if (self::isError($updated)) {
            throw new RuntimeException('SureCart PATCH failed: ' . esc_html(self::message($updated)));
        }
    }

    private static function isError(mixed $value): bool
    {
        return $value === false || (function_exists('is_wp_error') && is_wp_error($value));
    }

    private static function isNotFound(mixed $error): bool
    {
        if (!is_object($error) || !method_exists($error, 'get_error_code')) {
            return false;
        }
        $code = (string) $error->get_error_code();
        $data = method_exists($error, 'get_error_data') ? $error->get_error_data() : null;
        $status = is_array($data) ? ($data['status'] ?? null) : null;

        return $status === 404 || str_contains($code, 'not_found') || str_contains($code, 'not-found');
    }

    private static function message(mixed $error): string
    {
        return is_object($error) && method_exists($error, 'get_error_message') ? (string) $error->get_error_message() : 'unknown error';
    }
}
