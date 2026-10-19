<?php

declare(strict_types=1);

namespace Belayline\Plugin\Support;

/**
 * Request-scoped link between a governed call and the approval that authorised
 * it, so the execution audit row can name that approval.
 *
 * The id enters ONLY from {@see \Belayline\Plugin\Hooks\AbilityPermissionGate},
 * from the approval id the store returned when it reserved the grant for an
 * ALLOWED verdict. Nothing the agent sends (its arguments, an `approval_id`
 * key, the bearer token) is ever read here. Display and linkage only: the
 * entry is written after the allow/deny decision is final and feeds no gate.
 */
final class ApprovalLink
{
    /** @var array<string, string> verb => approval id reserved for the call about to run */
    private static array $bound = [];

    public static function bind(string $verb, string $approvalId): void
    {
        if ($verb !== '' && $approvalId !== '') {
            self::$bound[$verb] = $approvalId;
        }
    }

    /** The approval behind the run of $verb that is being audited, once; null when none. */
    public static function take(string $verb): ?string
    {
        $id = self::$bound[$verb] ?? null;
        unset(self::$bound[$verb]);

        return $id;
    }

    /** Forget everything (tests only). */
    public static function reset(): void
    {
        self::$bound = [];
    }
}
