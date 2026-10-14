<?php

declare(strict_types=1);

namespace Belayline\Plugin\Reviews;

use Belayline\Policy\Tier;

/**
 * What an administrator can decide about a third-party ability on the
 * Abilities screen. Stored by its string value in the review option.
 *
 * Read maps to Tier 0, Write to Tier 1 (the `reviewed-abilities` pack parks
 * it for approval) and Irreversible to Tier 2 (parks every time and is
 * ungrantable). Blocked has NO tier on purpose: an unmapped verb is refused
 * `unknown_verb` by every pack, `owner` included, which is the only way to make
 * a refusal that no pack binding can widen.
 */
enum ReviewTier: string
{
    case Read = 'read';
    case Write = 'write';
    case Irreversible = 'irreversible';
    case Blocked = 'blocked';

    public function tier(): ?Tier
    {
        return match ($this) {
            self::Read => Tier::Reversible,
            self::Write => Tier::SideEffecting,
            self::Irreversible => Tier::Irreversible,
            self::Blocked => null,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Read => __('Read (Tier 0, allowed and audited)', 'belayline-agent-governance'),
            self::Write => __('Write, approval needed', 'belayline-agent-governance'),
            self::Irreversible => __('Irreversible (parks every time, no pre-approval)', 'belayline-agent-governance'),
            self::Blocked => __('Blocked', 'belayline-agent-governance'),
        };
    }
}
