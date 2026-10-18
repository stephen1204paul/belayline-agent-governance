<?php

declare(strict_types=1);

namespace Belayline\Plugin\Admin;

use Belayline\Packs\Pack;
use Belayline\Plugin\Admin\Ui\Tiers;
use Belayline\Policy\Tier;
use Belayline\Policy\TierClassifier;

/**
 * What each pack does to the abilities registered on THIS site, for the Packs
 * tab: for every governed ability, whether the pack lets it run freely, asks
 * for approval, always asks, or refuses it. A read-only view computed with the
 * same pack and classifier methods the gate uses ({@see Pack::allows()},
 * {@see Pack::deniesClass()}, {@see Pack::requiresApproval()}); it never feeds a
 * decision. Argument-based elevations are not applied (there are no arguments
 * here), and they can only raise a tier, so the real answer can be stricter than
 * the page shows, never looser.
 */
final class PackOverview
{
    /** @var callable(): list<array{name: string, label: string, description: string, annotations: array<string, mixed>, schema: mixed}> */
    private $lister;

    /**
     * @param callable|null $lister Registered abilities; defaults to the Abilities API registry.
     */
    public function __construct(private readonly TierClassifier $classifier, ?callable $lister = null)
    {
        $this->lister = $lister ?? [AbilitiesPage::class, 'registeredAbilities'];
    }

    /**
     * @return array{rows: list<array{name: string, label: string, tier: string}>, counts: array<string, int>, refused: int}
     */
    public function forPack(Pack $pack): array
    {
        $rows = [];
        $counts = [Tiers::FREE => 0, Tiers::APPROVAL => 0, Tiers::ALWAYS => 0];
        $refused = 0;

        foreach (($this->lister)() as $ability) {
            $tier = $this->classifier->classify($ability['name']);
            if ($tier === null) {
                continue;
            }
            if (!$pack->allows($ability['name']) || $pack->deniesClass($tier)) {
                $refused++;
                continue;
            }
            $key = Tiers::FREE;
            if ($tier !== Tier::Reversible && $pack->requiresApproval($tier)) {
                $key = $tier === Tier::Irreversible ? Tiers::ALWAYS : Tiers::APPROVAL;
            }
            $counts[$key]++;
            $rows[] = ['name' => $ability['name'], 'label' => $ability['label'] !== '' ? $ability['label'] : $ability['name'], 'tier' => $key];
        }
        usort($rows, static fn (array $a, array $b): int => strcmp($a['name'], $b['name']));

        return ['rows' => $rows, 'counts' => $counts, 'refused' => $refused];
    }
}
