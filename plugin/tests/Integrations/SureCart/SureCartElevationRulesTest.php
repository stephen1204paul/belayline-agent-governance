<?php

declare(strict_types=1);

namespace Belayline\Plugin\Tests\Integrations\SureCart;

use PHPUnit\Framework\TestCase;
use Belayline\Plugin\Integrations\SureCart\DiscountElevationRule;
use Belayline\Plugin\Integrations\SureCart\SureCartIntegration;
use Belayline\Policy\Tier;
use Belayline\Policy\TierClassifier;
use Belayline\Policy\VerbCatalog;

final class SureCartElevationRulesTest extends TestCase
{
    protected function tearDown(): void
    {
        $GLOBALS['wpas_test_filters'] = [];
    }

    /** @param array<string, mixed> $args */
    private static function tier(string $verb, array $args): ?Tier
    {
        $catalog = new VerbCatalog();
        $c = SureCartIntegration::register($catalog);

        return (new TierClassifier($catalog, $c['elevationRules']))->classify($verb, $args);
    }

    /** @return array<string, array{string, array<string, mixed>, Tier}> */
    public static function cases(): array
    {
        $T1 = Tier::SideEffecting;
        $T2 = Tier::Irreversible;

        return [
            // update-price
            'price amount change' => ['surecart/update-price', ['id' => 'p', 'amount' => 1999], $T2],
            'price amount zero' => ['surecart/update-price', ['id' => 'p', 'amount' => 0], $T2],
            'price archive' => ['surecart/update-price', ['id' => 'p', 'archived' => true], $T2],
            'price archive string false is truthy to SureCart' => ['surecart/update-price', ['id' => 'p', 'archived' => 'false'], $T2],
            'price rename only' => ['surecart/update-price', ['id' => 'p', 'name' => 'Pro'], $T1],
            'price unarchive' => ['surecart/update-price', ['id' => 'p', 'archived' => false], $T1],
            'price amount null' => ['surecart/update-price', ['id' => 'p', 'amount' => null, 'name' => 'x'], $T1],
            // fulfillment
            'create-fulfillment notifications on' => ['surecart/create-fulfillment', ['order_id' => 'o', 'notifications_enabled' => true], $T2],
            'update-fulfillment notifications on' => ['surecart/update-fulfillment', ['id' => 'f', 'notifications_enabled' => 1], $T2],
            'fulfillment notifications off' => ['surecart/create-fulfillment', ['order_id' => 'o', 'notifications_enabled' => false], $T1],
            'fulfillment notifications absent' => ['surecart/update-fulfillment', ['id' => 'f', 'shipment_status' => 'shipped'], $T1],
            // discounts
            'coupon 50 percent' => ['surecart/create-coupon', ['name' => 'a', 'percent_off' => 50], $T2],
            'coupon 100 percent' => ['surecart/update-coupon', ['id' => 'c', 'percent_off' => 100], $T2],
            'coupon 49.9 percent' => ['surecart/create-coupon', ['name' => 'a', 'percent_off' => 49.9], $T1],
            'coupon 10 percent string' => ['surecart/create-coupon', ['name' => 'a', 'percent_off' => '10'], $T1],
            'coupon 50 percent string' => ['surecart/create-coupon', ['name' => 'a', 'percent_off' => '50'], $T2],
            'coupon amount at threshold' => ['surecart/create-coupon', ['name' => 'a', 'amount_off' => 10000], $T1],
            'coupon amount above threshold' => ['surecart/create-coupon', ['name' => 'a', 'amount_off' => 10001], $T2],
            'coupon negative amount absint' => ['surecart/update-coupon', ['id' => 'c', 'amount_off' => -20000], $T2],
            'coupon small amount' => ['surecart/create-coupon', ['name' => 'a', 'amount_off' => 500, 'currency' => 'usd'], $T1],
            'coupon array percent fails closed' => ['surecart/create-coupon', ['name' => 'a', 'percent_off' => [1]], $T2],
            'coupon unrelated update' => ['surecart/update-coupon', ['id' => 'c', 'name' => 'x', 'archived' => true], $T1],
            // not elevated by these args on other verbs
            'update-product ignores amount' => ['surecart/update-product', ['id' => 'p', 'amount' => 5], $T1],
            'list-orders ignores archived' => ['surecart/list-orders', ['archived' => true], Tier::Reversible],
        ];
    }

    /**
     * @dataProvider cases
     * @param array<string, mixed> $args
     */
    public function testElevation(string $verb, array $args, Tier $expected): void
    {
        $this->assertSame($expected, self::tier($verb, $args));
    }

    public function testAmountThresholdIsFilterable(): void
    {
        add_filter(DiscountElevationRule::AMOUNT_THRESHOLD_FILTER, static fn () => 1000);
        $this->assertSame(Tier::Irreversible, self::tier('surecart/create-coupon', ['amount_off' => 1001]));
        $this->assertSame(Tier::SideEffecting, self::tier('surecart/create-coupon', ['amount_off' => 1000]));
    }

    public function testBrokenThresholdFilterFallsBackToDefault(): void
    {
        add_filter(DiscountElevationRule::AMOUNT_THRESHOLD_FILTER, static fn () => 'lots');
        $this->assertSame(DiscountElevationRule::DEFAULT_AMOUNT_THRESHOLD, DiscountElevationRule::amountThreshold());
        $this->assertSame(Tier::Irreversible, self::tier('surecart/create-coupon', ['amount_off' => 10001]));
    }
}
