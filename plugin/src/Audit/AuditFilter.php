<?php

declare(strict_types=1);

namespace Belayline\Plugin\Audit;

/**
 * The filters the Activity > Calls table understands. Everything is optional;
 * an empty filter is "show everything". Values come straight from the query
 * string, so {@see fromRequest()} only ever keeps shapes it can use.
 */
final class AuditFilter
{
    /** The widest "last n days" choice offered, and the cap on token slots. */
    public const TOKEN_SLOTS = 5;

    /**
     * @param list<string> $tokens Identity tokens (exact), e.g. `app:...`, `user:3`.
     */
    public function __construct(
        public readonly string $outcome = '',
        public readonly string $pack = '',
        public readonly string $through = '',
        public readonly array $tokens = [],
        public readonly string $tokenPrefix = '',
        public readonly int $days = 0,
    ) {
    }

    /** True when no filter is active. */
    public function isEmpty(): bool
    {
        return $this->outcome === '' && $this->pack === '' && $this->through === '' && $this->tokens === []
            && $this->tokenPrefix === '' && $this->days === 0;
    }

    /**
     * @param array<string, mixed> $query Unslashed request values.
     */
    public static function fromRequest(array $query): self
    {
        $text = static fn (string $k): string => isset($query[$k]) && is_scalar($query[$k]) ? trim((string) $query[$k]) : '';
        $outcome = sanitize_key($text('outcome'));
        $tokens = array_values(array_filter(
            array_map(static fn (string $t): string => sanitize_text_field($t), explode(',', $text('token'))),
            static fn (string $t): bool => $t !== ''
        ));
        $days = (int) $text('days');

        return new self(
            in_array($outcome, ['ran', 'approved', 'waiting', 'refused', 'expired', 'failed', 'would'], true) ? $outcome : '',
            sanitize_text_field($text('pack')),
            sanitize_text_field($text('through')),
            array_slice($tokens, 0, self::TOKEN_SLOTS),
            sanitize_text_field($text('token_prefix')),
            in_array($days, [1, 7, 30], true) ? $days : 0,
        );
    }
}
