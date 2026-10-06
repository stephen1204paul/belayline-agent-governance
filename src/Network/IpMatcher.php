<?php

declare(strict_types=1);

namespace Specflux\AgentSafety\Network;

/**
 * Pure IPv4/IPv6 address and CIDR matching, plus the client-address rule for a
 * site behind proxies. No WordPress calls: the plugin host reads `$_SERVER` and
 * the stored lists, and hands plain strings here.
 *
 * Everything fails closed. A line that is not an address or a well-formed CIDR
 * is invalid (never silently dropped into a looser list), and an address that
 * cannot be determined matches nothing.
 *
 * An IPv4-mapped IPv6 address (`::ffff:1.2.3.4`) is treated as the IPv4
 * address it carries, so a dual-stack listener cannot make one client look
 * like two.
 */
final class IpMatcher
{
    /**
     * Split a newline-separated list into its valid entries (trimmed, in
     * order, de-duplicated) and the lines that are not a valid entry. Blank
     * lines are skipped.
     *
     * @return array{valid: list<string>, invalid: list<string>}
     */
    public static function parseList(string $text): array
    {
        $valid = [];
        $invalid = [];
        foreach (preg_split('/\R/', $text) ?: [] as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            if (self::parseEntry($line) === null) {
                $invalid[] = $line;
                continue;
            }
            $valid[$line] = $line;
        }

        return ['valid' => array_values($valid), 'invalid' => $invalid];
    }

    /** True when $entry is a single address or an address/prefix CIDR. */
    public static function isValidEntry(string $entry): bool
    {
        return self::parseEntry(trim($entry)) !== null;
    }

    /**
     * True when $ip is an address inside any of $entries. Invalid entries are
     * skipped; an invalid or empty $ip matches nothing.
     *
     * @param list<string> $entries
     */
    public static function matches(?string $ip, array $entries): bool
    {
        $bin = $ip === null ? null : self::toBinary(trim($ip));
        if ($bin === null) {
            return false;
        }

        foreach ($entries as $entry) {
            $parsed = self::parseEntry(trim($entry));
            if ($parsed === null || strlen($parsed['bin']) !== strlen($bin)) {
                continue;
            }
            if (self::prefixMatches($bin, $parsed['bin'], $parsed['bits'])) {
                return true;
            }
        }

        return false;
    }

    /**
     * The address the call really came from. REMOTE_ADDR unless it is itself
     * a trusted proxy, and only then X-Forwarded-For: walked from the right,
     * the first hop that is NOT a trusted proxy is the client. Hops further
     * left are whatever the client chose to send, so they are never reached.
     *
     * Null (refuse) when REMOTE_ADDR is missing or malformed, or when the walk
     * meets a hop that is not an address. When every hop is a trusted proxy
     * the left-most one is the client.
     *
     * @param list<string> $trustedProxies
     */
    public static function clientIp(?string $remoteAddr, ?string $forwardedFor, array $trustedProxies): ?string
    {
        $remote = self::canonical($remoteAddr);
        if ($remote === null) {
            return null;
        }

        if ($trustedProxies === [] || !self::matches($remote, $trustedProxies) || $forwardedFor === null || trim($forwardedFor) === '') {
            return $remote;
        }

        $hops = array_map('trim', explode(',', $forwardedFor));
        $hop = $remote;
        for ($i = count($hops) - 1; $i >= 0; $i--) {
            $hop = self::canonical($hops[$i]);
            if ($hop === null) {
                return null;
            }
            if (!self::matches($hop, $trustedProxies)) {
                return $hop;
            }
        }

        return $hop;
    }

    /** The textual form of a valid address (mapped IPv4 unwrapped), else null. */
    private static function canonical(?string $ip): ?string
    {
        $bin = $ip === null ? null : self::toBinary(trim($ip));

        return $bin === null ? null : (string) inet_ntop($bin);
    }

    /** @return array{bin: string, bits: int}|null */
    private static function parseEntry(string $entry): ?array
    {
        if ($entry === '' || preg_match('/\s/', $entry) === 1) {
            return null;
        }

        $parts = explode('/', $entry);
        if (count($parts) > 2) {
            return null;
        }

        $bin = self::toBinary($parts[0]);
        if ($bin === null) {
            return null;
        }

        $max = strlen($bin) * 8;
        if (count($parts) === 1) {
            return ['bin' => $bin, 'bits' => $max];
        }

        if (preg_match('/^\d{1,3}$/', $parts[1]) !== 1) {
            return null;
        }
        $bits = (int) $parts[1];

        return $bits <= $max ? ['bin' => $bin, 'bits' => $bits] : null;
    }

    private static function toBinary(string $ip): ?string
    {
        if ($ip === '' || filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return null;
        }

        $bin = inet_pton($ip);
        if ($bin === false) {
            return null;
        }

        if (strlen($bin) === 16 && str_starts_with($bin, "\0\0\0\0\0\0\0\0\0\0\xff\xff")) {
            return substr($bin, 12);
        }

        return $bin;
    }

    private static function prefixMatches(string $a, string $b, int $bits): bool
    {
        $bytes = intdiv($bits, 8);
        if (substr($a, 0, $bytes) !== substr($b, 0, $bytes)) {
            return false;
        }

        $rest = $bits % 8;
        if ($rest === 0) {
            return true;
        }

        $mask = (0xff << (8 - $rest)) & 0xff;

        return (ord($a[$bytes]) & $mask) === (ord($b[$bytes]) & $mask);
    }
}
