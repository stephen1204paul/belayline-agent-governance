<?php

declare(strict_types=1);

namespace Specflux\AgentSafety\Approval;

use Specflux\AgentSafety\Audit\Redactor;

/**
 * Renders a call's arguments as one compact, human-readable line for the
 * approval screen, for abilities with no verb-specific summary:
 *
 *   senroflux/update-settings { settings.blogdescription: "Hello" }
 *
 * Pure PHP (no WordPress calls). The output is always TEXT — callers escape it;
 * it is never markup. Nested associative arrays are flattened with dots, lists
 * collapse to "N items", every value is capped, and the whole is capped.
 *
 * Masking: the audit {@see Redactor} PII list runs first, then a secret-key list
 * (the audit Redactor has none): any path segment containing one of
 * {@see self::SECRET_KEYS} is masked, same mask string as the audit trail.
 */
final class ArgumentSummarizer
{
    public const MAX_TOTAL = 300;
    public const MAX_VALUE = 80;
    private const MAX_DEPTH = 4;
    private const MASK = '«redacted»';

    /** Lower-cased key fragments whose values are never shown. */
    private const SECRET_KEYS = [
        'password', 'passwd', 'passphrase', 'secret', 'token', 'api_key', 'apikey',
        'access_key', 'private_key', 'auth', 'credential', 'cookie', 'nonce',
        'signature', 'bearer', 'license_key', 'session',
    ];

    /**
     * @param array<string, mixed> $args
     */
    public static function summarize(string $verb, array $args): string
    {
        unset($args[ApprovalBinding::TOKEN_ARG]);
        $parts = [];
        self::flatten(Redactor::apply($args, true), '', 0, $parts);
        if ($parts === []) {
            return $verb;
        }

        $body = '';
        $truncated = false;
        foreach ($parts as $part) {
            $next = $body === '' ? $part : $body . ', ' . $part;
            if (self::len($next) > self::MAX_TOTAL) {
                $truncated = true;
                if ($body === '') {
                    $body = self::cut($next, self::MAX_TOTAL);
                }
                break;
            }
            $body = $next;
        }

        return $verb . ' { ' . $body . ($truncated ? ', …' : '') . ' }';
    }

    /**
     * @param array<int|string, mixed> $data
     * @param list<string> $parts
     */
    private static function flatten(array $data, string $prefix, int $depth, array &$parts): void
    {
        foreach ($data as $key => $value) {
            $path = self::clean($prefix === '' ? (string) $key : $prefix . '.' . $key);
            if (is_string($key) && self::isSecret($key)) {
                $parts[] = $path . ': ' . self::MASK;
                continue;
            }
            if (is_array($value)) {
                if (array_is_list($value)) {
                    $parts[] = $path . ': ' . count($value) . (count($value) === 1 ? ' item' : ' items');
                } elseif ($depth + 1 >= self::MAX_DEPTH) {
                    $parts[] = $path . ': ' . count($value) . ' fields';
                } else {
                    self::flatten($value, $path, $depth + 1, $parts);
                }
                continue;
            }
            $parts[] = $path . ': ' . self::scalar($value);
        }
    }

    private static function scalar(mixed $v): string
    {
        if ($v === null) {
            return 'null';
        }
        if (is_bool($v)) {
            return $v ? 'true' : 'false';
        }
        if (is_int($v) || is_float($v)) {
            return (string) $v;
        }
        if (!is_string($v)) {
            return '(' . gettype($v) . ')';
        }
        $v = self::clean($v);
        if (self::len($v) > self::MAX_VALUE) {
            $v = self::cut($v, self::MAX_VALUE - 1) . '…';
        }

        return '"' . $v . '"';
    }

    /** Collapse whitespace/control characters so one value stays on one line. */
    private static function clean(string $s): string
    {
        return trim((string) preg_replace('/[\x00-\x1F\x7F]+|\s+/u', ' ', $s));
    }

    private static function isSecret(string $key): bool
    {
        $key = strtolower($key);
        foreach (self::SECRET_KEYS as $needle) {
            if (str_contains($key, $needle)) {
                return true;
            }
        }

        return false;
    }

    private static function len(string $s): int
    {
        return function_exists('mb_strlen') ? mb_strlen($s) : strlen($s);
    }

    private static function cut(string $s, int $n): string
    {
        return function_exists('mb_substr') ? mb_substr($s, 0, $n) : substr($s, 0, $n);
    }
}
