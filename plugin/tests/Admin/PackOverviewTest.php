<?php

declare(strict_types=1);

namespace Belayline\Plugin\Tests\Admin;

use Belayline\Packs\Pack;
use Belayline\Plugin\Admin\PackOverview;
use Belayline\Plugin\Admin\Ui\Tiers;
use Belayline\Policy\Tier;
use Belayline\Policy\TierClassifier;
use Belayline\Policy\VerbCatalog;
use PHPUnit\Framework\TestCase;

/** The Packs tab's per-pack summary is computed with the same pack and classifier methods the gate uses. */
final class PackOverviewTest extends TestCase
{
    private function overview(): PackOverview
    {
        $catalog = new VerbCatalog();
        $catalog->register([
            'shop/list' => Tier::Reversible,
            'shop/update' => Tier::SideEffecting,
            'shop/refund' => Tier::Irreversible,
            'shop/hold' => Tier::Irreversible,
        ]);
        $abilities = array_map(
            static fn (string $name): array => ['name' => $name, 'label' => '', 'description' => '', 'annotations' => [], 'schema' => null],
            ['shop/list', 'shop/update', 'shop/refund', 'shop/hold', 'other/unmapped']
        );

        return new PackOverview(new TierClassifier($catalog), static fn (): array => $abilities);
    }

    public function testCountsFollowTheGateRules(): void
    {
        $pack = new Pack('shop', ['shop/*'], denyClass: [], approvalByClass: ['tier1' => true, 'tier2' => true]);

        $view = $this->overview()->forPack($pack);

        $this->assertSame([Tiers::FREE => 1, Tiers::APPROVAL => 1, Tiers::ALWAYS => 2], $view['counts']);
        $this->assertSame(0, $view['refused']);
        $this->assertSame(['shop/hold', 'shop/list', 'shop/refund', 'shop/update'], array_column($view['rows'], 'name'));
        $this->assertSame('12 abilities', sprintf('%d abilities', 12));
        $this->assertSame('4 abilities · 1 run freely · 1 need approval · 2 always ask', Tiers::meta(4, $view['counts']));
    }

    public function testAHardDeniedClassAndAnUnlistedAbilityAreRefusedNotListed(): void
    {
        $pack = new Pack('read-only', ['shop/list', 'shop/update'], denyClass: ['tier1']);

        $view = $this->overview()->forPack($pack);

        $this->assertSame(['shop/list'], array_column($view['rows'], 'name'));
        $this->assertSame(3, $view['refused'], 'update (denied class), refund and hold (not in the pack)');
    }

    public function testAnOwnerLikePackWithoutApprovalRunsEverythingFreely(): void
    {
        $view = $this->overview()->forPack(new Pack('owner', ['*']));

        $this->assertSame([Tiers::FREE => 4, Tiers::APPROVAL => 0, Tiers::ALWAYS => 0], $view['counts']);
    }

    public function testAnAbilityTheCatalogDoesNotKnowIsLeftOut(): void
    {
        $view = $this->overview()->forPack(new Pack('owner', ['*']));

        $this->assertNotContains('other/unmapped', array_column($view['rows'], 'name'));
    }

    public function testASingularSummaryReadsNaturally(): void
    {
        $this->assertSame('1 ability · 1 run freely · 0 need approval · 0 always ask', Tiers::meta(1, [Tiers::FREE => 1]));
        $this->assertSame('3 abilities · 1 run freely · 0 need approval · 0 always ask · 1 blocked · 1 not reviewed', Tiers::meta(3, [Tiers::FREE => 1, Tiers::BLOCKED => 1, Tiers::NONE => 1]));
    }
}
