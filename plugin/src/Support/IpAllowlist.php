<?php

declare(strict_types=1);

namespace Belayline\Plugin\Support;

use Belayline\Network\IpMatcher;

/**
 * The optional IP allowlist for credentials an agent uses from outside the
 * browser: application passwords (`app:` tokens) and WooCommerce API keys
 * (`wc:` tokens). A logged-in cookie session carries only `user:`/`role:`
 * tokens, so it is never IP-checked — the person is at their own browser, and
 * checking it would turn a mistyped list into an admin lock-out.
 *
 * Two administrator-set options, newline-separated: `belayline_ip_allowlist`
 * (addresses and CIDR ranges; empty means every address is allowed) and
 * `belayline_trusted_proxies` (same format). Only when REMOTE_ADDR is itself a
 * trusted proxy is X-Forwarded-For read, and then only up to its right-most
 * untrusted hop ({@see IpMatcher::clientIp()}); a site behind a CDN that
 * names no proxy would otherwise allowlist the CDN.
 *
 * Fail closed: a stored list holding any non-blank line enforces, even if
 * every line is malformed (a hand-edited option denies rather than opens),
 * and an address that cannot be determined is refused. A refusal is the deny
 * reason `ip_not_allowed`, decided in the verdict pipeline beside the pause
 * ({@see \Belayline\Plugin\Verdict\VerdictPipeline}).
 */
final class IpAllowlist
{
    public const OPTION = 'belayline_ip_allowlist';

    public const PROXIES_OPTION = 'belayline_trusted_proxies';

    /** The deny reason every refused call carries. */
    public const REASON = 'ip_not_allowed';

    /** Token prefixes of credentials used from outside the browser. */
    private const REMOTE_PREFIXES = ['app:', 'wc:'];

    public function __construct(private readonly AdminChangeRecorder $changes = new AdminChangeRecorder())
    {
    }

    /** True when the stored list has any content, i.e. the allowlist is switched on. */
    public function isEnforcing(): bool
    {
        $parsed = IpMatcher::parseList($this->stored(self::OPTION));

        return $parsed['valid'] !== [] || $parsed['invalid'] !== [];
    }

    /** True when this request authenticated with a remote credential (never for a cookie session). */
    public function isRemoteCredential(): bool
    {
        foreach (RequestContext::currentTokens() as $token) {
            foreach (self::REMOTE_PREFIXES as $prefix) {
                if (str_starts_with($token, $prefix)) {
                    return true;
                }
            }
        }

        return false;
    }

    /** Should THIS request's call be refused for where it came from? */
    public function refuses(): bool
    {
        if (!$this->isEnforcing() || !$this->isRemoteCredential()) {
            return false;
        }

        return !IpMatcher::matches($this->clientIp(), IpMatcher::parseList($this->stored(self::OPTION))['valid']);
    }

    /**
     * This request's client address under the stored trusted-proxies list, or
     * $proxiesText when given (the admin form previewing an unsaved setting).
     */
    public function clientIp(?string $proxiesText = null): ?string
    {
        $proxies = IpMatcher::parseList($proxiesText ?? $this->stored(self::PROXIES_OPTION))['valid'];

        return IpMatcher::clientIp($this->server('REMOTE_ADDR'), $this->server('HTTP_X_FORWARDED_FOR'), $proxies);
    }

    /** Would the proposed settings refuse the address this request is coming from? */
    public function wouldExcludeCurrentIp(string $allowlistText, string $proxiesText): bool
    {
        $entries = IpMatcher::parseList($allowlistText)['valid'];
        if ($entries === []) {
            return false;
        }

        return !IpMatcher::matches($this->clientIp($proxiesText), $entries);
    }

    /** @return list<string> */
    public function entries(): array
    {
        return IpMatcher::parseList($this->stored(self::OPTION))['valid'];
    }

    /** @return list<string> */
    public function trustedProxies(): array
    {
        return IpMatcher::parseList($this->stored(self::PROXIES_OPTION))['valid'];
    }

    /**
     * Validate and store both lists, audited. Any malformed line rejects the
     * WHOLE save and nothing changes: a half-applied list would be a guess
     * about what the administrator meant.
     *
     * @return array{saved: bool, invalid: list<string>, excludesCurrentIp: bool}
     */
    public function save(string $allowlistText, string $proxiesText, int $byUserId): array
    {
        $allow = IpMatcher::parseList($allowlistText);
        $proxies = IpMatcher::parseList($proxiesText);
        $invalid = array_merge($allow['invalid'], $proxies['invalid']);
        if ($invalid !== []) {
            return ['saved' => false, 'invalid' => $invalid, 'excludesCurrentIp' => false];
        }

        $excludes = $this->wouldExcludeCurrentIp($allowlistText, $proxiesText);
        $fromAllow = $this->entries();
        $fromProxies = $this->trustedProxies();

        update_option(self::OPTION, implode("\n", $allow['valid']), false);
        update_option(self::PROXIES_OPTION, implode("\n", $proxies['valid']), false);

        if ($fromAllow !== $allow['valid'] || $fromProxies !== $proxies['valid']) {
            $this->changes->ipAllowlistChanged($byUserId, $fromAllow, $allow['valid'], $fromProxies, $proxies['valid']);
        }

        return ['saved' => true, 'invalid' => [], 'excludesCurrentIp' => $excludes];
    }

    private function stored(string $option): string
    {
        $raw = get_option($option, '');

        return is_string($raw) ? $raw : '';
    }

    private function server(string $key): ?string
    {
        if (!isset($_SERVER[$key]) || !is_string($_SERVER[$key])) {
            return null;
        }

        $value = sanitize_text_field(wp_unslash($_SERVER[$key]));

        return $value !== '' ? $value : null;
    }
}
