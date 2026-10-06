<?php

declare(strict_types=1);

namespace Specflux\AgentSafety\Plugin\Tests\Integrations\Woo;

use PHPUnit\Framework\TestCase;
use Specflux\AgentSafety\Plugin\Integrations\Woo\CustomerNoteElevationRule;
use Specflux\AgentSafety\Plugin\Integrations\Woo\ForceDeleteElevationRule;
use Specflux\AgentSafety\Plugin\Integrations\Woo\OrderFulfillmentElevationRule;
use Specflux\AgentSafety\Plugin\Integrations\Woo\OrderMoneyShapingElevationRule;
use Specflux\AgentSafety\Plugin\Integrations\Woo\ProductPriceOrPublishElevationRule;
use Specflux\AgentSafety\Policy\Tier;

/**
 * The Woo rules elevate ONLY on their documented argument shape and never
 * touch anything else — same contract the core rules are held to in
 * {@see \Specflux\AgentSafety\Plugin\Tests\Integrations\Core\ElevationRulesTest}.
 * The force-delete rule is the fail-closed one: only a CLEARLY false flag
 * keeps the base tier, every other shape elevates.
 */
final class ElevationRulesTest extends TestCase
{
    private const ORDER_UPDATE = 'woocommerce/orders-update';
    private const ORDER_UPDATE_STATUS = 'woocommerce/order-update-status';
    private const PRODUCT_DELETE = 'woocommerce/products-delete';
    private const PRODUCT_CREATE = 'woocommerce/product-create';
    private const PRODUCT_UPDATE = 'woocommerce/product-update';
    private const ORDER_ADD_NOTE = 'woocommerce/order-add-note';

    public function testStatusTransitionsWithCustomerFacingSideEffectsElevateToIrreversible(): void
    {
        $rule = new OrderFulfillmentElevationRule();

        foreach ([self::ORDER_UPDATE, self::ORDER_UPDATE_STATUS] as $verb) {
            foreach (['processing', 'completed', 'shipped', 'cancelled', 'refunded', 'Cancelled', ' cancelled', "cancelled\n", " Cancelled \t"] as $status) {
                $this->assertSame(
                    Tier::Irreversible,
                    $rule->apply($verb, ['id' => 42, 'status' => $status], Tier::SideEffecting),
                    sprintf('%s status "%s" must elevate', $verb, $status),
                );
            }
        }
    }

    public function testStatusTransitionsWithoutSideEffectsStayAtBaseTier(): void
    {
        $rule = new OrderFulfillmentElevationRule();

        foreach ([self::ORDER_UPDATE, self::ORDER_UPDATE_STATUS] as $verb) {
            foreach (['draft', 'auto-draft', ''] as $status) {
                $this->assertNull($rule->apply($verb, ['id' => 42, 'status' => $status], Tier::SideEffecting));
            }
            $this->assertNull($rule->apply($verb, ['id' => 42, 'customer_note' => 'x'], Tier::SideEffecting));
        }
    }

    public function testStatusRuleIgnoresOtherVerbs(): void
    {
        $rule = new OrderFulfillmentElevationRule();

        $this->assertNull($rule->apply('core/manage-content', ['status' => 'refunded'], Tier::SideEffecting));
    }

    public function testProductCreateOrUpdateWithAPriceElevatesToIrreversible(): void
    {
        $rule = new ProductPriceOrPublishElevationRule();

        foreach ([self::PRODUCT_CREATE, self::PRODUCT_UPDATE, 'woocommerce/products-create', 'woocommerce/products-update'] as $verb) {
            $this->assertSame(
                Tier::Irreversible,
                $rule->apply($verb, ['id' => 1, 'regular_price' => '9.99'], Tier::SideEffecting),
                sprintf('%s with regular_price must elevate', $verb),
            );
            $this->assertSame(
                Tier::Irreversible,
                $rule->apply($verb, ['id' => 1, 'sale_price' => '7.99'], Tier::SideEffecting),
                sprintf('%s with sale_price must elevate', $verb),
            );
        }
    }

    public function testProductCreateOrUpdateThatPublishesElevatesToIrreversible(): void
    {
        $rule = new ProductPriceOrPublishElevationRule();

        foreach (['publish', 'future', 'Publish', ' publish', "publish\n", " Publish \t"] as $status) {
            $this->assertSame(
                Tier::Irreversible,
                $rule->apply(self::PRODUCT_CREATE, ['status' => $status], Tier::SideEffecting),
                sprintf('status "%s" must elevate', $status),
            );
            $this->assertSame(
                Tier::Irreversible,
                $rule->apply(self::PRODUCT_UPDATE, ['status' => $status], Tier::SideEffecting),
                sprintf('status "%s" must elevate', $status),
            );
        }
    }

    public function testProductCreateOrUpdateWithoutPriceOrPublishStaysAtBaseTier(): void
    {
        $rule = new ProductPriceOrPublishElevationRule();

        $this->assertNull($rule->apply(self::PRODUCT_CREATE, ['name' => 'Widget'], Tier::SideEffecting));
        $this->assertNull($rule->apply(self::PRODUCT_UPDATE, ['status' => 'draft'], Tier::SideEffecting));
        $this->assertNull($rule->apply(self::PRODUCT_UPDATE, ['regular_price' => null], Tier::SideEffecting));
        $this->assertNull($rule->apply(self::PRODUCT_UPDATE, ['sale_price' => null], Tier::SideEffecting));
    }

    public function testProductPriceOrPublishRuleIgnoresOtherVerbs(): void
    {
        $rule = new ProductPriceOrPublishElevationRule();

        $this->assertNull($rule->apply(self::PRODUCT_DELETE, ['regular_price' => '9.99'], Tier::Irreversible));
        $this->assertNull($rule->apply(self::ORDER_UPDATE, ['status' => 'publish'], Tier::SideEffecting));
    }

    public function testCustomerNoteTrueElevatesToIrreversible(): void
    {
        $rule = new CustomerNoteElevationRule();

        foreach ([true, 1, '1'] as $customerNote) {
            $this->assertSame(
                Tier::Irreversible,
                $rule->apply(self::ORDER_ADD_NOTE, ['id' => 42, 'note' => 'hi', 'customer_note' => $customerNote], Tier::SideEffecting),
                sprintf('customer_note=%s must elevate', var_export($customerNote, true)),
            );
        }
    }

    public function testCustomerNoteFalseOrAbsentStaysAtBaseTier(): void
    {
        $rule = new CustomerNoteElevationRule();

        $this->assertNull($rule->apply(self::ORDER_ADD_NOTE, ['id' => 42, 'note' => 'hi'], Tier::SideEffecting));
        foreach ([false, 0, '', null] as $customerNote) {
            $this->assertNull(
                $rule->apply(self::ORDER_ADD_NOTE, ['id' => 42, 'note' => 'hi', 'customer_note' => $customerNote], Tier::SideEffecting),
                sprintf('customer_note=%s must not elevate', var_export($customerNote, true)),
            );
        }
    }

    public function testCustomerNoteRuleIgnoresOtherVerbs(): void
    {
        $rule = new CustomerNoteElevationRule();

        $this->assertNull($rule->apply(self::ORDER_UPDATE, ['customer_note' => true], Tier::SideEffecting));
        $this->assertNull($rule->apply(self::PRODUCT_UPDATE, ['customer_note' => true], Tier::SideEffecting));
    }

    public function testForceFlagThatIsNotClearlyFalseElevatesProductDeleteToIrreversible(): void
    {
        $rule = new ForceDeleteElevationRule();

        foreach ([true, 1, '1', 'true', 'TRUE', 'yes', 'no', 2, 0.0, [1], ['false'], null] as $force) {
            $this->assertSame(
                Tier::Irreversible,
                $rule->apply(self::PRODUCT_DELETE, ['id' => 7, 'force' => $force], Tier::SideEffecting),
                sprintf('force=%s must elevate', var_export($force, true)),
            );
        }
    }

    public function testClearlyFalseForceFlagKeepsTheBaseTier(): void
    {
        $rule = new ForceDeleteElevationRule();

        $this->assertNull($rule->apply(self::PRODUCT_DELETE, ['id' => 7], Tier::SideEffecting), 'absent');
        foreach ([false, 0, '0', '', 'false', 'FALSE', 'False'] as $force) {
            $this->assertNull(
                $rule->apply(self::PRODUCT_DELETE, ['id' => 7, 'force' => $force], Tier::SideEffecting),
                sprintf('force=%s must not elevate', var_export($force, true)),
            );
        }
    }

    public function testForceRuleIgnoresOtherVerbs(): void
    {
        $rule = new ForceDeleteElevationRule();

        $this->assertNull($rule->apply(self::ORDER_UPDATE, ['id' => 42, 'force' => true], Tier::SideEffecting));
        $this->assertNull($rule->apply('woocommerce/products-update', ['id' => 7, 'force' => true], Tier::SideEffecting));
    }

    public function testOrdersCreateElevatesOnCustomerFacingStatuses(): void
    {
        $rule = new OrderFulfillmentElevationRule();

        foreach (['processing', 'completed', 'on-hold', 'pending', 'On-Hold', ' pending '] as $status) {
            $this->assertSame(Tier::Irreversible, $rule->apply('woocommerce/orders-create', ['status' => $status], Tier::SideEffecting), $status);
        }
        $this->assertNull($rule->apply('woocommerce/orders-create', ['billing' => ['first_name' => 'A']], Tier::SideEffecting));
    }

    public function testOnHoldAndPendingElevateOnEveryStatusVerb(): void
    {
        $rule = new OrderFulfillmentElevationRule();

        foreach ([self::ORDER_UPDATE, self::ORDER_UPDATE_STATUS] as $verb) {
            foreach (['on-hold', 'pending'] as $status) {
                $this->assertSame(Tier::Irreversible, $rule->apply($verb, ['id' => 1, 'status' => $status], Tier::SideEffecting));
            }
        }
    }

    public function testSetPaidTrueElevatesAnyOrderWriteEvenWithoutStatus(): void
    {
        $rule = new OrderFulfillmentElevationRule();

        foreach (['woocommerce/orders-create', self::ORDER_UPDATE] as $verb) {
            foreach ([true, 'true', 1, '1'] as $paid) {
                $this->assertSame(Tier::Irreversible, $rule->apply($verb, ['id' => 1, 'set_paid' => $paid], Tier::SideEffecting), var_export($paid, true));
            }
            $this->assertSame(Tier::Irreversible, $rule->apply($verb, ['set_paid' => true, 'status' => 'draft'], Tier::SideEffecting));
        }
    }

    public function testSetPaidFalseOrAbsentStaysAsBefore(): void
    {
        $rule = new OrderFulfillmentElevationRule();

        foreach (['woocommerce/orders-create', self::ORDER_UPDATE] as $verb) {
            foreach ([false, 'false', 0, '0', null] as $paid) {
                $this->assertNull($rule->apply($verb, ['id' => 1, 'set_paid' => $paid], Tier::SideEffecting), var_export($paid, true));
            }
            $this->assertNull($rule->apply($verb, ['id' => 1, 'set_paid' => false, 'status' => 'draft'], Tier::SideEffecting));
            $this->assertSame(Tier::Irreversible, $rule->apply($verb, ['id' => 1, 'set_paid' => false, 'status' => 'completed'], Tier::SideEffecting));
        }
        $this->assertNull($rule->apply('woocommerce/products-update', ['set_paid' => true], Tier::SideEffecting));
    }

    public function testMoneyShapingOrderArgsElevateOnCreateAndUpdate(): void
    {
        $rule = new OrderMoneyShapingElevationRule();

        foreach (['woocommerce/orders-create', self::ORDER_UPDATE] as $verb) {
            foreach (['line_items', 'fee_lines', 'shipping_lines', 'coupon_lines', 'customer_id'] as $key) {
                $value = $key === 'customer_id' ? 9 : [['id' => 1]];
                $this->assertSame(Tier::Irreversible, $rule->apply($verb, ['id' => 3, $key => $value], Tier::SideEffecting), "$verb $key");
            }
        }
        // customer_id 0 (reassign to guest) is still a change of who is billed.
        $this->assertSame(Tier::Irreversible, $rule->apply(self::ORDER_UPDATE, ['customer_id' => 0], Tier::SideEffecting));
    }

    public function testMoneyShapingRuleLeavesOtherShapesAlone(): void
    {
        $rule = new OrderMoneyShapingElevationRule();

        $this->assertNull($rule->apply(self::ORDER_UPDATE, ['id' => 3, 'customer_note' => 'hi', 'billing' => ['city' => 'x']], Tier::SideEffecting));
        $this->assertNull($rule->apply(self::ORDER_UPDATE, ['id' => 3, 'line_items' => null, 'customer_id' => null], Tier::SideEffecting));
        $this->assertNull($rule->apply(self::ORDER_UPDATE_STATUS, ['id' => 3, 'line_items' => [['id' => 1]]], Tier::SideEffecting));
        $this->assertNull($rule->apply('woocommerce/products-update', ['line_items' => [['id' => 1]]], Tier::SideEffecting));
    }

    public function testGuardedMetaKeysCannotBypassThePriceRule(): void
    {
        $rule = new ProductPriceOrPublishElevationRule();

        foreach (['_price', '_regular_price', '_sale_price', '_stock', '_manage_stock', '_REGULAR_PRICE', ' _regular_price '] as $key) {
            foreach ([self::PRODUCT_UPDATE, self::PRODUCT_CREATE, 'woocommerce/products-update', 'woocommerce/products-create'] as $verb) {
                $args = ['id' => 7, 'meta_data' => [['key' => 'color', 'value' => 'red'], ['key' => $key, 'value' => '0.01']]];
                $this->assertSame(Tier::Irreversible, $rule->apply($verb, $args, Tier::SideEffecting), "$verb $key");
            }
        }
    }

    public function testHarmlessOrMalformedMetaDataStaysAtBaseTier(): void
    {
        $rule = new ProductPriceOrPublishElevationRule();

        $this->assertNull($rule->apply(self::PRODUCT_UPDATE, ['meta_data' => [['key' => 'color', 'value' => 'red'], ['key' => '_price_note', 'value' => 'x']]], Tier::SideEffecting));
        $this->assertNull($rule->apply(self::PRODUCT_UPDATE, ['meta_data' => 'nope'], Tier::SideEffecting));
        $this->assertNull($rule->apply(self::PRODUCT_UPDATE, ['meta_data' => [['value' => '_regular_price'], 'x', ['key' => 5]]], Tier::SideEffecting));
        $this->assertNull($rule->apply(self::ORDER_UPDATE, ['meta_data' => [['key' => '_regular_price', 'value' => '1']]], Tier::SideEffecting));
    }

    public function testIsCustomerNoteAlsoElevates(): void
    {
        $rule = new CustomerNoteElevationRule();

        $this->assertSame(Tier::Irreversible, $rule->apply(self::ORDER_ADD_NOTE, ['id' => 1, 'note' => 'x', 'is_customer_note' => true], Tier::SideEffecting));
        $this->assertNull($rule->apply(self::ORDER_ADD_NOTE, ['id' => 1, 'note' => 'x', 'is_customer_note' => false], Tier::SideEffecting));
    }
}
