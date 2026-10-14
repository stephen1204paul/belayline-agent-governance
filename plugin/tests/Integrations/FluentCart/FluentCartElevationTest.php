<?php

declare(strict_types=1);

namespace Belayline\Plugin\Tests\Integrations\FluentCart;

use PHPUnit\Framework\TestCase;
use Belayline\Plugin\Integrations\FluentCart\CouponActivationElevationRule;
use Belayline\Plugin\Integrations\FluentCart\DryRunRelaxation;
use Belayline\Plugin\Integrations\FluentCart\FluentCartIntegration;
use Belayline\Policy\Tier;
use Belayline\Policy\TierClassifier;
use Belayline\Policy\VerbCatalog;
use wpdb;

final class FluentCartElevationTest extends TestCase
{
    /** @param array<int, string> $coupons id => stored status */
    private static function classifier(array $coupons = []): TierClassifier
    {
        $db = new class ($coupons) extends wpdb {
            /** @param array<int, string> $coupons */
            public function __construct(private array $coupons)
            {
                parent::__construct();
            }

            // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- mirrors wpdb::get_var()
            public function get_var(?string $query = null): mixed
            {
                preg_match('/`id` = (\d+)/', (string) $query, $m);

                return $this->coupons[(int) ($m[1] ?? 0)] ?? null;
            }
        };
        $catalog = new VerbCatalog();
        $c = FluentCartIntegration::register($catalog, $db);

        return new TierClassifier($catalog, $c['elevationRules']);
    }

    /**
     * @dataProvider cases
     * @param array<string, mixed> $args
     * @param array<int, string> $coupons
     */
    public function testElevation(string $verb, array $args, Tier $expected, array $coupons = []): void
    {
        $this->assertSame($expected, self::classifier($coupons)->classify($verb, $args), $verb . ' ' . json_encode($args));
    }

    /** @return array<string, array<mixed>> */
    public static function cases(): array
    {
        $note = 'fluent-cart/add-order-note';
        $cust = 'fluent-cart/upsert-customer';
        $coupon = 'fluent-cart/manage-coupon';

        return [
            // customer-visible note
            'note internal' => [$note, ['order_id' => 5, 'note' => 'x'], Tier::SideEffecting],
            'note customer_note=true' => [$note, ['order_id' => 5, 'note' => 'x', 'customer_note' => true], Tier::Irreversible],
            'note notify_customer=1' => [$note, ['order_id' => 5, 'note' => 'x', 'notify_customer' => 1], Tier::Irreversible],
            'note type=customer' => [$note, ['order_id' => 5, 'note' => 'x', 'type' => 'Customer'], Tier::Irreversible],
            'note customer_note=false' => [$note, ['order_id' => 5, 'note' => 'x', 'customer_note' => false], Tier::SideEffecting],
            // customer email change
            'customer create' => [$cust, ['email' => 'a@b.co', 'first_name' => 'A'], Tier::SideEffecting],
            'customer update names only' => [$cust, ['customer_id' => 3, 'email' => 'a@b.co', 'last_name' => 'Z'], Tier::SideEffecting],
            'customer new_email' => [$cust, ['customer_id' => 3, 'new_email' => 'n@b.co'], Tier::Irreversible],
            'customer new_email by email match' => [$cust, ['email' => 'a@b.co', 'new_email' => 'n@b.co'], Tier::Irreversible],
            'customer empty new_email' => [$cust, ['customer_id' => 3, 'new_email' => ''], Tier::SideEffecting],
            // coupons
            'coupon create inactive' => [$coupon, ['action' => 'create', 'code' => 'X', 'type' => 'fixed', 'amount' => 5, 'status' => 'inactive'], Tier::SideEffecting],
            'coupon create default active' => [$coupon, ['action' => 'create', 'code' => 'X', 'type' => 'fixed', 'amount' => 5], Tier::Irreversible],
            'coupon create active' => [$coupon, ['action' => 'create', 'code' => 'X', 'type' => 'fixed', 'amount' => 5, 'status' => 'active'], Tier::Irreversible],
            'coupon deactivate' => [$coupon, ['action' => 'deactivate', 'coupon_id' => 7], Tier::SideEffecting, [7 => 'active']],
            'coupon update enabling' => [$coupon, ['action' => 'update', 'coupon_id' => 7, 'status' => 'active'], Tier::Irreversible, [7 => 'inactive']],
            'coupon update disabled coupon' => [$coupon, ['action' => 'update', 'coupon_id' => 7, 'amount' => 9], Tier::SideEffecting, [7 => 'inactive']],
            'coupon update active coupon' => [$coupon, ['action' => 'update', 'coupon_id' => 7, 'amount' => 9], Tier::Irreversible, [7 => 'active']],
            'coupon update unreadable coupon' => [$coupon, ['action' => 'update', 'coupon_id' => 8, 'amount' => 9], Tier::Irreversible, [7 => 'inactive']],
            'coupon update no id' => [$coupon, ['action' => 'update', 'amount' => 9], Tier::Irreversible],
            'coupon unknown action' => [$coupon, ['action' => 'frobnicate', 'coupon_id' => 7], Tier::Irreversible, [7 => 'active']],
            'coupon update to inactive' => [$coupon, ['action' => 'update', 'coupon_id' => 7, 'status' => 'inactive'], Tier::SideEffecting, [7 => 'active']],
        ];
    }

    public function testCouponRuleWithoutResolverFailsClosed(): void
    {
        $rule = new CouponActivationElevationRule();

        $this->assertSame(Tier::Irreversible, $rule->apply('fluent-cart/manage-coupon', ['action' => 'update', 'coupon_id' => 7, 'amount' => 1], Tier::SideEffecting));
        $this->assertNull($rule->apply('fluent-cart/list-coupons', [], Tier::Reversible));
    }

    public function testRefundAndCancelAreIrreversibleAndNeverGrantable(): void
    {
        $classifier = self::classifier();
        foreach (['fluent-cart/refund-order', 'fluent-cart/change-subscription-status'] as $verb) {
            $this->assertSame(Tier::Irreversible, $classifier->classify($verb, ['dry_run' => true]), 'dry_run does not relax (relaxation is off)');
            // Even a site filter that says "eligible" cannot make them grantable.
            $this->assertFalse(FluentCartIntegration::grantEligible(true, null, $verb, []));
        }
        $this->assertFalse(FluentCartIntegration::grantEligible(false, null, 'fluent-cart/manage-coupon', []));
        $this->assertTrue(FluentCartIntegration::grantEligible(true, null, 'fluent-cart/manage-coupon', []));
        $this->assertFalse(FluentCartIntegration::grantEligible('yes', null, 'fluent-cart/manage-coupon', []));
    }

    public function testDryRunRelaxationIsOffByDefault(): void
    {
        $this->assertFalse(DryRunRelaxation::ENABLED);
        $this->assertFalse(DryRunRelaxation::applies('fluent-cart/refund-order', ['dry_run' => true]));
        // classification ignores dry_run entirely
        $this->assertSame(Tier::SideEffecting, self::classifier()->classify('fluent-cart/apply-labels', ['dry_run' => true]));
    }

    public function testDryRunRelaxationLogicWhenEnabledIsStrict(): void
    {
        $this->assertTrue(DryRunRelaxation::applies('fluent-cart/refund-order', ['dry_run' => true], true));
        $this->assertFalse(DryRunRelaxation::applies('fluent-cart/refund-order', ['dry_run' => 'true'], true));
        $this->assertFalse(DryRunRelaxation::applies('fluent-cart/refund-order', ['dry_run' => true, 'confirm_token' => 'abc'], true));
        $this->assertFalse(DryRunRelaxation::applies('fluent-cart/refund-order', ['dry_run' => false], true));
        $this->assertFalse(DryRunRelaxation::applies('fluent-cart/list-orders', ['dry_run' => true], true), 'reads need no relaxation');
        $this->assertFalse(DryRunRelaxation::applies('fluent-cart/unknown', ['dry_run' => true], true));
    }
}
