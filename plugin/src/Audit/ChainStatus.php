<?php

declare(strict_types=1);

namespace Belayline\Plugin\Audit;

/**
 * The audit chain's last verification, cached so the admin screens do not walk
 * the whole log on every page load. {@see AuditReader::verifyChain()} stays the
 * only verifier; this only remembers its answer (including a failure) for five
 * minutes, per site, and says when it was checked.
 */
final class ChainStatus
{
    public const CACHE_KEY = 'belayline_chain_status';
    public const TTL = 300;

    public function __construct(private readonly AuditReader $reader)
    {
    }

    /**
     * @return array{ok: bool, at: int, total: int}
     */
    public function get(int $now, bool $force = false): array
    {
        $cached = get_transient(self::CACHE_KEY);
        if (
            !$force && is_array($cached) && is_bool($cached['ok'] ?? null)
            && is_int($cached['at'] ?? null) && is_int($cached['total'] ?? null)
            && $now - $cached['at'] < self::TTL
        ) {
            return ['ok' => $cached['ok'], 'at' => $cached['at'], 'total' => $cached['total']];
        }

        $status = ['ok' => $this->reader->verifyChain(), 'at' => $now, 'total' => $this->reader->total()];
        set_transient(self::CACHE_KEY, $status, self::TTL);

        return $status;
    }
}
