<?php

declare(strict_types=1);

namespace Belayline\Plugin\Reviews;

use Belayline\Packs\Pack;
use Belayline\Plugin\Api\AbilityReviews;

/**
 * The one generic pack that admits reviewed third-party abilities.
 *
 * A call is only allowed when the pack's allow-list names its verb, and the
 * built-in `default-agent` allows nothing, so a reviewed ability is callable
 * by a credential only after an administrator binds that credential to this
 * pack (Tools, Agent Capability Packs). The allow-list is the exact set of
 * reviewed, non-blocked abilities, never a glob over a namespace, so an
 * ability that was not reviewed is not admitted even inside a reviewed
 * namespace. Tier 1 (Write) and Tier 2 (Irreversible) both need approval;
 * only Read runs straight through.
 *
 * Packs that allow `*` (`owner`) admit reviewed abilities too, on their own
 * terms. A Blocked review is unmapped, so even `owner` is refused.
 */
final class ReviewedAbilitiesPack
{
    public const NAME = 'reviewed-abilities';

    public static function build(AbilityReviews $reviews): Pack
    {
        return new Pack(
            name: self::NAME,
            allow: $reviews->admittedVerbs(),
            approvalByClass: ['tier1' => true, 'tier2' => true],
        );
    }
}
