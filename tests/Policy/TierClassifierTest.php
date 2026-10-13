<?php

declare(strict_types=1);

namespace Belayline\Tests\Policy;

use PHPUnit\Framework\TestCase;
use Belayline\Policy\ElevationRule;
use Belayline\Policy\Tier;
use Belayline\Policy\TierClassifier;
use Belayline\Policy\VerbCatalog;
use Belayline\Tests\Fixtures\BulkDeleteElevationRuleFixture;
use Belayline\Tests\Fixtures\FulfillmentElevationRuleFixture;
use Belayline\Tests\Fixtures\WooLikeVerbCatalog;

final class TierClassifierTest extends TestCase
{
    public function testDefaultConstructionIsAnEmptyCatalogAndFailsClosed(): void
    {
        $classifier = new TierClassifier();

        $this->assertNull($classifier->classify('anything/at-all'));
    }

    public function testKnownVerbReturnsBaseTierWithNoRules(): void
    {
        $catalog = new VerbCatalog();
        $catalog->register(['demo/read' => Tier::Reversible]);
        $classifier = new TierClassifier($catalog);

        $this->assertSame(Tier::Reversible, $classifier->classify('demo/read'));
    }

    public function testElevationRuleThatDoesNotFireLeavesBaseTierUntouched(): void
    {
        $classifier = new TierClassifier(WooLikeVerbCatalog::build(), [new FulfillmentElevationRuleFixture()]);

        $this->assertSame(Tier::SideEffecting, $classifier->classify('woocommerce/orders-update', ['note' => 'x']));
    }

    public function testFulfillmentStatusElevatesOrderUpdateToIrreversible(): void
    {
        $classifier = new TierClassifier(WooLikeVerbCatalog::build(), [new FulfillmentElevationRuleFixture()]);

        $this->assertSame(Tier::Irreversible, $classifier->classify('woocommerce/orders-update', ['status' => 'completed']));
    }

    public function testBulkDeleteElevatesProductsDeleteToIrreversible(): void
    {
        $classifier = new TierClassifier(WooLikeVerbCatalog::build(), [new BulkDeleteElevationRuleFixture()]);

        $this->assertSame(Tier::SideEffecting, $classifier->classify('woocommerce/products-delete', ['ids' => [1]]));
        $this->assertSame(Tier::Irreversible, $classifier->classify('woocommerce/products-delete', ['ids' => [1, 2, 3]]));
    }

    public function testMultipleRulesStackInOrder(): void
    {
        $classifier = new TierClassifier(
            WooLikeVerbCatalog::build(),
            [new FulfillmentElevationRuleFixture(), new BulkDeleteElevationRuleFixture()],
        );

        $this->assertSame(Tier::Irreversible, $classifier->classify('woocommerce/orders-update', ['status' => 'shipped']));
        $this->assertSame(Tier::Irreversible, $classifier->classify('woocommerce/products-delete', ['ids' => [1, 2]]));
    }

    public function testUnknownVerbIsNullRegardlessOfRules(): void
    {
        $classifier = new TierClassifier(WooLikeVerbCatalog::build(), [new FulfillmentElevationRuleFixture()]);

        $this->assertNull($classifier->classify('woocommerce/orders-teleport', ['status' => 'completed']));
    }

    public function testIsReadonlyButWritesStillFailsClosedOnAWriteVerb(): void
    {
        $classifier = new TierClassifier(WooLikeVerbCatalog::build());

        $this->assertTrue($classifier->isReadonlyButWrites('woocommerce/products-update', true, ['id' => 1]));
        $this->assertFalse($classifier->isReadonlyButWrites('woocommerce/products-list', true, []));
        $this->assertFalse($classifier->isReadonlyButWrites('woocommerce/products-update', false, ['id' => 1]));
    }

    public function testARuleCanNeverLowerATier(): void
    {
        // The ElevationRule contract has always said rules only elevate; this is
        // the enforcement. Without it one buggy or hostile rule could demote an
        // irreversible verb straight out of the approval gate — and the
        // `belayline_elevation_rules` seam lets rules come from outside the
        // bundled integration modules.
        $catalog = new VerbCatalog();
        $catalog->register(['demo/delete-everything' => Tier::Irreversible]);
        $lowering = new class implements ElevationRule {
            public function apply(string $verb, array $args, Tier $currentTier): ?Tier
            {
                return Tier::Reversible;
            }
        };

        $classifier = new TierClassifier($catalog, [$lowering]);

        $this->assertSame(Tier::Irreversible, $classifier->classify('demo/delete-everything'));
    }

    public function testALaterRuleCannotUndoAnEarlierRulesElevation(): void
    {
        $catalog = new VerbCatalog();
        $catalog->register(['demo/save' => Tier::Reversible]);
        $raise = new class implements ElevationRule {
            public function apply(string $verb, array $args, Tier $currentTier): ?Tier
            {
                return Tier::Irreversible;
            }
        };
        $lower = new class implements ElevationRule {
            public function apply(string $verb, array $args, Tier $currentTier): ?Tier
            {
                return Tier::SideEffecting;
            }
        };

        $classifier = new TierClassifier($catalog, [$raise, $lower]);

        $this->assertSame(Tier::Irreversible, $classifier->classify('demo/save'));
    }
}
