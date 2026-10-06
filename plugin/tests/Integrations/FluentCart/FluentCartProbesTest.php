<?php

declare(strict_types=1);

namespace Specflux\AgentSafety\Plugin\Tests\Integrations\FluentCart;

use PHPUnit\Framework\TestCase;
use Specflux\AgentSafety\Plugin\Integrations\FluentCart\FluentCartCouponStateProbe;
use Specflux\AgentSafety\Plugin\Integrations\FluentCart\FluentCartCustomerStateProbe;
use Specflux\AgentSafety\Plugin\Integrations\FluentCart\FluentCartIntegration;
use Specflux\AgentSafety\Plugin\Integrations\FluentCart\FluentCartOrderStateProbe;
use Specflux\AgentSafety\Plugin\Integrations\FluentCart\FluentCartSubscriptionStateProbe;
use Specflux\AgentSafety\Policy\VerbCatalog;
use wpdb;

final class FluentCartProbesTest extends TestCase
{
    public function testOrderProbeReadsTheOrdersRowByOrderId(): void
    {
        $db = new wpdb();
        $db->rowReturn = ['status' => 'processing', 'total_paid' => '5000', 'total_refund' => '0'];

        $facts = (new FluentCartOrderStateProbe($db))->read('fluent-cart/refund-order', ['order_id' => '42', 'dry_run' => true]);

        $this->assertSame($db->rowReturn, $facts);
        $sql = end($db->queries);
        $this->assertStringContainsString('FROM `wp_fct_orders` WHERE `id` = 42', $sql);
        $this->assertStringContainsString('`total_refund`', $sql);
    }

    public function testMissingRowOrIdIsNullSoTheCallIsRefusedNotTreatedAsUnchanged(): void
    {
        $db = new wpdb();
        $probe = new FluentCartOrderStateProbe($db);

        $db->rowReturn = null;
        $this->assertNull($probe->read('fluent-cart/change-order-status', ['order_id' => 9]));
        $this->assertNull($probe->read('fluent-cart/change-order-status', []));
        $this->assertNull($probe->read('fluent-cart/change-order-status', ['order_id' => 'abc; DROP']));
    }

    public function testTargetArgsAreOnlyTheIdentifier(): void
    {
        $probe = new FluentCartOrderStateProbe(new wpdb());

        $this->assertSame(['order_id' => 42], $probe->targetArgs('fluent-cart/refund-order', ['order_id' => '42', 'amount' => 1, 'note' => 'x']));
        $this->assertSame([], $probe->targetArgs('fluent-cart/refund-order', []));
    }

    public function testSubscriptionProbeUsesSubscriptionId(): void
    {
        $db = new wpdb();
        $db->rowReturn = ['status' => 'active'];
        $probe = new FluentCartSubscriptionStateProbe($db);

        $this->assertSame(['status' => 'active'], $probe->read('fluent-cart/change-subscription-status', ['subscription_id' => 3]));
        $this->assertStringContainsString('`wp_fct_subscriptions` WHERE `id` = 3', end($db->queries));
        $this->assertSame(['subscription_id' => 3], $probe->targetArgs('v', ['subscription_id' => 3]));
    }

    public function testCouponProbeExcludesUseCountAndAllowsCreate(): void
    {
        $db = new wpdb();
        $db->rowReturn = ['status' => 'active'];
        $probe = new FluentCartCouponStateProbe($db);

        $probe->read('fluent-cart/manage-coupon', ['action' => 'update', 'coupon_id' => 7]);
        $this->assertStringNotContainsString('use_count', end($db->queries));
        $this->assertStringContainsString('`wp_fct_coupons` WHERE `id` = 7', end($db->queries));

        $queries = count($db->queries);
        $this->assertSame(['target' => 'new'], $probe->read('fluent-cart/manage-coupon', ['action' => 'create', 'code' => 'X']));
        $this->assertCount($queries, $db->queries, 'create does not hit the database');
        $this->assertNull($probe->read('fluent-cart/manage-coupon', ['action' => 'update']), 'update without an id is unverifiable');
    }

    public function testCustomerProbeByIdByEmailAndForNewCustomer(): void
    {
        $db = new wpdb();
        $probe = new FluentCartCustomerStateProbe($db);

        $db->rowReturn = ['email' => 'a@b.co', 'status' => 'active'];
        $this->assertSame($db->rowReturn, $probe->read('fluent-cart/upsert-customer', ['customer_id' => 4]));
        $this->assertStringContainsString('`wp_fct_customers` WHERE `id` = 4', end($db->queries));

        $this->assertSame($db->rowReturn, $probe->read('fluent-cart/upsert-customer', ['email' => ' A@B.co ']));
        $this->assertStringContainsString("`email` = 'a@b.co'", end($db->queries));
        $this->assertSame(['email' => 'a@b.co'], $probe->targetArgs('fluent-cart/upsert-customer', ['email' => ' A@B.co ', 'first_name' => 'x']));

        $db->rowReturn = null;
        $this->assertSame(['target' => 'new'], $probe->read('fluent-cart/upsert-customer', ['email' => 'new@b.co']));
        $this->assertNull($probe->read('fluent-cart/upsert-customer', ['customer_id' => 99]), 'unknown id is unverifiable');
        $this->assertNull($probe->read('fluent-cart/upsert-customer', []));
    }

    public function testRegisterWiresProbesForTargetedWritesOnly(): void
    {
        $contrib = FluentCartIntegration::register(new VerbCatalog(), new wpdb());

        $this->assertSame(
            [
                'fluent-cart/change-order-status',
                'fluent-cart/change-subscription-status',
                'fluent-cart/manage-coupon',
                'fluent-cart/refund-order',
                'fluent-cart/upsert-customer',
            ],
            self::sorted(array_keys($contrib['stateProbes'])),
        );
        $this->assertArrayNotHasKey('fluent-cart/add-order-note', $contrib['stateProbes']);
        $this->assertArrayNotHasKey('fluent-cart/apply-labels', $contrib['stateProbes']);
    }

    /**
     * @param list<string> $a
     * @return list<string>
     */
    private static function sorted(array $a): array
    {
        sort($a);

        return $a;
    }
}
